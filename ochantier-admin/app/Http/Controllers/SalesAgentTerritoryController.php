<?php

namespace App\Http\Controllers;

use App\Enums\SalesAgentActivityCategory;
use App\Enums\SalesAgentAvailability;
use App\Enums\SalesAgentStatus;
use App\Http\Requests\WorkerRequest;
use App\Models\SalesAgent;
use App\Models\SalesAgentPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Territoire et disponibilités : zones attribuées, transferts, indisponibilités et remplacements temporaires.
 */
class SalesAgentTerritoryController extends Controller
{
    public const UNAVAILABILITY_REASONS = ['Congé annuel', 'Maladie', 'Formation', 'Raison familiale', 'Mission hors zone', 'Autre'];

    /**
     * Listes modifiables depuis l’onglet : segment d’URL → [colonne, libellé].
     */
    private const LISTS = [
        'zones-secondaires' => ['secondary_zones', 'Zone secondaire'],
        'communes' => ['communes', 'Commune couverte'],
        'metiers-prioritaires' => ['priority_trades', 'Métier prioritaire'],
    ];

    /**
     * Attribue une zone secondaire, ou remplace la zone principale (l’ancienne devient secondaire).
     */
    public function addZone(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403);

        $data = $request->validate([
            'zone' => ['required', Rule::in(WorkerRequest::COMMUNES), Rule::notIn([$salesAgent->zone, ...$salesAgent->secondary_zones])],
            'kind' => ['required', Rule::in(['secondary', 'primary'])],
        ], ['zone.required' => 'Ce champ est obligatoire.', 'zone.not_in' => 'Zone déjà attribuée à ce commercial.']);

        $zone = $data['zone'];
        $communes = in_array($zone, $salesAgent->communes, true) ? $salesAgent->communes : [...$salesAgent->communes, $zone];

        if ($data['kind'] === 'primary') {
            $old = $salesAgent->zone;
            $salesAgent->update(['zone' => $zone, 'secondary_zones' => [$old, ...$salesAgent->secondary_zones], 'communes' => $communes]);
            $salesAgent->logActivity(SalesAgentActivityCategory::Zone, 'a changé la zone principale', $old, $zone);
        } else {
            $salesAgent->update(['secondary_zones' => [...$salesAgent->secondary_zones, $zone], 'communes' => $communes]);
            $salesAgent->logActivity(SalesAgentActivityCategory::Zone, 'a attribué une zone secondaire', null, $zone);
        }
        Inertia::flash('toast', 'Zone attribuée.');

        return back();
    }

    /**
     * Confie une zone secondaire (et ses dossiers en cours) à un autre commercial actif.
     */
    public function transferZone(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403);

        $data = $request->validate([
            'zone' => ['required', Rule::in($salesAgent->secondary_zones)],
            'to' => ['required', 'string'],
            'comment' => ['required', 'string', 'min:5', 'max:500'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'comment.min' => 'Justification trop courte (5 caractères minimum).']);

        $target = SalesAgent::where('status', SalesAgentStatus::Active)->whereKeyNot($salesAgent->id)->get()->first(fn (SalesAgent $a): bool => $a->name === $data['to']);
        if (! $target) {
            return back()->withErrors(['to' => 'Choisissez un commercial actif.']);
        }

        DB::transaction(function () use ($salesAgent, $target, $data): void {
            $salesAgent->update(['secondary_zones' => array_values(array_diff($salesAgent->secondary_zones, [$data['zone']]))]);
            if ($target->zone !== $data['zone'] && ! in_array($data['zone'], $target->secondary_zones, true)) {
                $target->update(['secondary_zones' => [...$target->secondary_zones, $data['zone']]]);
            }
            $salesAgent->logActivity(SalesAgentActivityCategory::Zone, 'a transféré une zone', $data['zone'], $target->name, trim($data['comment']));
            $target->logActivity(SalesAgentActivityCategory::Zone, 'a reçu une zone transférée', null, $data['zone'], "Depuis {$salesAgent->name}");
        });
        Inertia::flash('toast', "Zone transférée à {$target->name}.");

        return back();
    }

    public function removeValue(Request $request, SalesAgent $salesAgent, string $list): RedirectResponse
    {
        abort_unless(isset(self::LISTS[$list]), 404);
        abort_if($salesAgent->isArchived(), 403);

        [$column, $label] = self::LISTS[$list];
        $value = $request->validate(['value' => ['required', 'string', Rule::in($salesAgent->{$column})]])['value'];

        $salesAgent->update([$column => array_values(array_diff($salesAgent->{$column}, [$value]))]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Zone, 'a retiré une valeur', $value, null, $label);
        Inertia::flash('toast', "« {$value} » retiré.");

        return back();
    }

    /**
     * Déclare une indisponibilité, avec un remplaçant temporaire éventuel.
     */
    public function unavailability(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_if($salesAgent->isArchived() || $salesAgent->status->isPreActivation(), 422, 'Indisponibilité impossible pour ce compte.');

        $types = [SalesAgentAvailability::Leave, SalesAgentAvailability::Unavailable, SalesAgentAvailability::Partial, SalesAgentAvailability::FieldMission];
        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'type' => ['required', Rule::in(array_map(fn (SalesAgentAvailability $t): string => $t->value, $types))],
            'reason' => ['required', Rule::in(self::UNAVAILABILITY_REASONS)],
            'replacement' => ['nullable', 'string', 'max:120'],
            'transfer' => ['nullable', Rule::in(['Oui', 'Non'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'ends_on.after_or_equal' => 'La date de fin doit suivre la date de début.']);

        $type = SalesAgentAvailability::from($data['type']);
        $replacement = $data['replacement'] ?: null;
        $comment = trim((string) ($data['comment'] ?? ''));
        if (($data['transfer'] ?? 'Non') === 'Oui' && $replacement) {
            $comment = ltrim("{$comment} · Dossiers transférés à {$replacement}", ' ·');
        }

        $salesAgent->periods()->create([
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'type' => $type,
            'reason' => $data['reason'],
            'zone' => $salesAgent->zone,
            'replacement' => $replacement,
            'comment' => $comment ?: null,
        ]);

        $old = $salesAgent->availability->label();
        if (today()->between(Carbon::parse($data['starts_on']), Carbon::parse($data['ends_on'])) && $salesAgent->status === SalesAgentStatus::Active) {
            $salesAgent->update(['availability' => $type]);
        }
        $period = Carbon::parse($data['starts_on'])->translatedFormat('d M Y').' → '.Carbon::parse($data['ends_on'])->translatedFormat('d M Y');
        $salesAgent->logActivity(SalesAgentActivityCategory::Availability, 'a déclaré une indisponibilité', $old, $type->label(), "{$period} · {$data['reason']}".($replacement ? " · remplaçant : {$replacement}" : ''));
        Inertia::flash('toast', 'Indisponibilité enregistrée.');

        return back();
    }

    public function destroyPeriod(SalesAgent $salesAgent, SalesAgentPeriod $period): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403);

        if ($salesAgent->availability === $period->type && $salesAgent->status === SalesAgentStatus::Active) {
            $salesAgent->update(['availability' => SalesAgentAvailability::Available]);
        }
        $label = $period->starts_on->translatedFormat('d M Y').' → '.$period->ends_on->translatedFormat('d M Y');
        $salesAgent->logActivity(SalesAgentActivityCategory::Availability, 'a supprimé une période', $period->type->label(), null, $label);
        $period->delete();
        Inertia::flash('toast', 'Période supprimée.');

        return back();
    }
}
