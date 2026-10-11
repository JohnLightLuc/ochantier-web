<?php

namespace App\Http\Controllers;

use App\Models\MatchingAudit;
use App\Models\MatchingCycle;
use App\Models\MatchingNotification;
use App\Models\NeedOffer;
use App\Support\MatchingEngine;
use App\Support\NeedInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cycles de Matching : lancement (assistant), annulation, actualisation et alerte des commerciaux.
 */
class MatchingRunController extends Controller
{
    public const SCOPES = ['Un besoin précis', 'Plusieurs besoins', 'Un chantier', 'Un promoteur', 'Une zone', 'Un métier', 'Les besoins urgents', 'Tous les besoins éligibles'];

    /**
     * Lance un cycle sur le périmètre choisi : les résultats sont recalculés et datés du jour.
     */
    public function launch(Request $request, MatchingEngine $engine): RedirectResponse
    {
        $data = $this->validated($request);
        $config = MatchingEngine::config();
        if (! $config['ok']) {
            throw ValidationException::withMessages(['scope' => 'Configuration du moteur de Matching requise dans Paramètres.']);
        }
        $scope = $this->scope($engine, $data);
        if ($scope->isEmpty()) {
            throw ValidationException::withMessages(['scope' => 'Aucun besoin éligible dans ce périmètre.']);
        }

        $results = $scope->map(fn (NeedOffer $o): array => $engine->results($o));
        $cycle = MatchingCycle::create([
            'reference' => $this->nextReference(),
            'ran_at' => $engine->today()->format('Y-m-d').' '.now()->format('H:i:s'),
            'scope' => $this->scopeLabel($engine, $data),
            'needs' => $scope->count(),
            'workers' => $results->sum(fn (array $r): int => count($r['all'])),
            'recommendations' => $results->sum(fn (array $r): int => count($r['top'])),
            'without_profile' => $results->filter(fn (array $r): bool => ! $r['ok'])->count(),
            'excluded' => $results->sum(fn (array $r): int => count($r['ex'])),
            'warnings' => $results->sum(fn (array $r): int => collect($r['ok'])->filter(fn (array $x): bool => (bool) $x['sc']['warnings'])->count()),
            'duration' => max(2, (int) $request->input('duration', 2)),
            'initiator' => $request->user()?->name ?? 'Système',
            'status' => 'Terminé',
            'algorithm' => $config['algorithm'],
            'zone' => $data['scope'] === 'Une zone' ? $data['target'] : 'Toutes zones',
            'trade' => $data['scope'] === 'Un métier' ? $data['target'] : 'Tous métiers',
        ]);
        $scope->each(fn (NeedOffer $o) => $o->update(['last_cycle' => $cycle->reference, 'last_matched_on' => $engine->today(), 'last_matched_by' => $cycle->initiator]));

        MatchingAudit::record('a lancé un cycle de Matching', $cycle->reference, "{$cycle->scope} · {$cycle->needs} besoin(s)");
        MatchingNotification::send('Résultat de Matching disponible', $cycle->initiator, 'Notification interne', "{$cycle->reference} : {$cycle->recommendations} recommandation(s) pour {$cycle->needs} besoin(s).", 'Consulter les résultats');
        if ($cycle->without_profile) {
            MatchingNotification::send('Besoin sans résultat', 'Commerciaux', 'SMS', "{$cycle->without_profile} besoin(s) sans profil compatible.", 'Relancer');
        }
        Inertia::flash('toast', "Matching terminé · {$cycle->recommendations} recommandation(s).");

        return redirect("/matching/lancer?cycle={$cycle->reference}");
    }

    /**
     * Annulation d’un traitement en cours : le cycle est conservé avec le statut « Annulé ».
     */
    public function cancel(Request $request, MatchingEngine $engine): RedirectResponse
    {
        $data = $this->validated($request);
        $cycle = MatchingCycle::create([
            'reference' => $this->nextReference(), 'ran_at' => now(), 'scope' => $data['scope'], 'needs' => $this->scope($engine, $data)->count(), 'duration' => 1,
            'initiator' => $request->user()?->name ?? 'Système', 'status' => 'Annulé', 'algorithm' => config('admin.matching.algorithm'), 'zone' => 'Toutes zones', 'trade' => 'Tous métiers',
        ]);
        MatchingAudit::record('a annulé un cycle de Matching', $cycle->reference);
        Inertia::flash('toast', 'Traitement annulé.');

        return redirect("/matching/lancer?cycle={$cycle->reference}");
    }

    public function refresh(): RedirectResponse
    {
        Inertia::flash('toast', 'Besoins et recommandations actualisés.');

        return back();
    }

    /**
     * Alerte les commerciaux de la zone pour un poste sans profil compatible.
     */
    public function notifySales(NeedOffer $offer): RedirectResponse
    {
        $offer->load('need.site', 'line');
        $zone = NeedInsights::zone($offer->need->site->commune);
        MatchingNotification::send('Besoin sans résultat', "Commerciaux {$zone}", 'SMS', "{$offer->reference} · {$offer->line->trade} recherché à {$offer->need->site->commune}", 'Recruter');
        Inertia::flash('toast', 'Commerciaux notifiés.');

        return back();
    }

    /**
     * @return array{scope: string, target: string|null, multi: array<int, int>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(self::SCOPES)],
            'target' => [Rule::requiredIf(fn (): bool => in_array($request->input('scope'), ['Un besoin précis', 'Un chantier', 'Un promoteur', 'Une zone', 'Un métier'], true)), 'nullable', 'string'],
            'multi' => [Rule::requiredIf(fn (): bool => $request->input('scope') === 'Plusieurs besoins'), 'nullable', 'array'],
            'multi.*' => ['integer'],
        ], ['target.required' => 'Ce champ est obligatoire.', 'multi.required' => 'Sélectionnez au moins un besoin.']);

        return ['scope' => $data['scope'], 'target' => $data['target'] ?? null, 'multi' => array_map('intval', $data['multi'] ?? [])];
    }

    /**
     * @param  array{scope: string, target: string|null, multi: array<int, int>}  $data
     * @return Collection<int, NeedOffer>
     */
    private function scope(MatchingEngine $engine, array $data): Collection
    {
        $target = $data['target'];

        return $engine->eligible()->filter(fn (NeedOffer $o): bool => match ($data['scope']) {
            'Un besoin précis' => (string) $o->id === $target,
            'Plusieurs besoins' => in_array($o->id, $data['multi'], true),
            'Un chantier' => $o->need->site->name === $target,
            'Un promoteur' => $o->need->site->promoter_name === $target,
            'Une zone' => NeedInsights::zone($o->need->site->commune) === $target,
            'Un métier' => $o->line->trade === $target,
            'Les besoins urgents' => $o->urgency->isPressing(),
            default => true,
        })->values();
    }

    /**
     * @param  array{scope: string, target: string|null, multi: array<int, int>}  $data
     */
    private function scopeLabel(MatchingEngine $engine, array $data): string
    {
        if ($data['scope'] === 'Un besoin précis' && ($offer = $engine->offer((int) $data['target']))) {
            return "Un besoin précis · {$offer->reference} · {$offer->need->site->name}";
        }

        return $data['scope'].($data['target'] ? " · {$data['target']}" : ($data['multi'] ? ' · '.count($data['multi']).' besoin(s)' : ''));
    }

    private function nextReference(): string
    {
        $last = MatchingCycle::query()->pluck('reference')->map(fn (string $r): int => (int) substr($r, -3))->max() ?? 30;

        return 'CYC-2026-'.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }
}
