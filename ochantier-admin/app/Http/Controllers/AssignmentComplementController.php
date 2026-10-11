<?php

namespace App\Http\Controllers;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedUrgency;
use App\Http\Requests\NeedRequest;
use App\Models\Need;
use App\Models\PromoterOffer;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Nouveau besoin en ouvriers sur un chantier en cours (renfort), rattaché au besoin ou à l’offre d’origine :
 * il est publié aussitôt et suivi dans « Postes et ouvriers manquants ».
 */
class AssignmentComplementController extends Controller
{
    /**
     * Corps de métier par défaut de chaque métier.
     *
     * @var array<string, string>
     */
    private const TRADE_GROUPS = [
        'Maçon' => 'Gros œuvre', 'Manœuvre' => 'Gros œuvre', 'Coffreur' => 'Structure', 'Ferrailleur' => 'Structure', 'Électricien' => 'Électricité',
        'Plombier' => 'Réseaux', 'Menuisier' => 'Menuiserie', 'Soudeur' => 'Métallerie', 'Peintre' => 'Finitions', 'Carreleur' => 'Finitions',
    ];

    public function forNeed(Request $request, Need $need): RedirectResponse
    {
        $need->load('lines', 'offers.line', 'offers.assignments', 'assignments', 'site');
        abort_unless(! $need->manual_status && $need->status() === Need::DONE, 422, 'Un renfort ne peut être ajouté qu’à un besoin comblé, chantier en cours.');

        return $this->create($request, $need->site, $need->reference);
    }

    public function forOffer(Request $request, PromoterOffer $promoterOffer): RedirectResponse
    {
        $promoterOffer->load('profiles', 'site');
        abort_unless($promoterOffer->status() === PromoterOffer::DONE, 422, 'Un renfort ne peut être ajouté qu’à une offre comblée, chantier en cours.');

        return $this->create($request, $promoterOffer->site, $promoterOffer->reference);
    }

    private function create(Request $request, Site $site, string $parent): RedirectResponse
    {
        $today = Need::referenceDate();
        $data = $request->validate([
            'trade' => ['required', Rule::in(array_keys(NeedRequest::TRADE_DETAILS))],
            'quantity' => ['required', 'integer', 'min:1', 'max:200'],
            'experience' => ['required', Rule::in(NeedRequest::EXPERIENCES)],
            'urgency' => ['required', Rule::in(array_map(fn (NeedUrgency $u): string => $u->label(), NeedUrgency::cases()))],
            'starts_on' => ['required', 'date', 'after:'.$today->format('Y-m-d')],
            'duration' => ['required', 'integer', 'min:1', 'max:730'],
            'daily_cost' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'starts_on.after' => 'La date de début doit être postérieure à aujourd’hui.',
            'quantity.*' => 'Le nombre d’ouvriers doit être supérieur à zéro.',
            'duration.*' => 'La durée doit être supérieure à zéro.',
            'daily_cost.*' => 'Le coût journalier doit être supérieur à zéro.',
        ]);
        $urgency = NeedUrgency::fromLabel($data['urgency']);
        $details = NeedRequest::TRADE_DETAILS[$data['trade']];
        $startsOn = $today->parse($data['starts_on']);
        $reason = trim($data['reason'] ?? '') ?: 'Renfort pour le chantier en cours';

        $need = DB::transaction(function () use ($data, $site, $parent, $today, $urgency, $details, $startsOn, $reason): Need {
            $number = Need::nextNumber();
            $need = Need::create([
                'slug' => "bes-2026-{$number}",
                'reference' => "BES-2026-{$number}",
                'site_id' => $site->id,
                'urgency' => $urgency,
                'type' => 'Renfort d’équipe',
                'source' => 'Application Promoteur',
                'admin' => auth()->user()?->name,
                'contact' => $site->manager ?? $site->promoter_name,
                'contact_phone' => preg_replace('/\D/', '', (string) $site->manager_phone) ?: '0700000000',
                'starts_on' => $startsOn,
                'ends_on' => $startsOn->addDays((int) $data['duration'] - 1),
                'working_hours' => '07:30 – 17:00 · pause 12:00 – 13:00',
                'working_days' => 'Lun, Mar, Mer, Jeu, Ven, Sam',
                'justification' => $urgency->isPressing() ? $reason : null,
                'requirements' => ['Pièce d’identité', 'Profil vérifié'],
                'anomalies' => [],
                'parent_reference' => $parent,
                'created_on' => $today,
                'updated_on' => $today,
            ]);
            $line = $need->lines()->create([
                'position' => 1, 'trade_group' => self::TRADE_GROUPS[$data['trade']] ?? 'Gros œuvre', 'trade' => $data['trade'], 'specialty' => $details['specialties'][0] ?? null,
                'quantity' => (int) $data['quantity'], 'experience' => $data['experience'], 'daily_cost' => (int) $data['daily_cost'], 'pay_type' => 'Journalier',
                'skills' => array_slice($details['skills'], 0, 2), 'certifications' => [],
            ]);
            $plural = $line->quantity > 1 ? 's' : '';
            $need->offers()->create([
                'need_line_id' => $line->id, 'reference' => "{$need->reference}/1", 'title' => "{$line->trade}{$plural} · {$site->name}", 'urgency' => $urgency,
                'visibility' => 'Ouvriers éligibles uniquement', 'published_on' => $today, 'deadline' => $startsOn->subDay(), 'starts_on' => $need->starts_on, 'ends_on' => $need->ends_on,
                'applications_open' => true, 'description' => "Renfort de {$line->quantity} ".mb_strtolower($line->trade)."{$plural} pour le chantier {$site->name}.",
            ]);
            $need->logActivity(NeedActivityCategory::Creation, 'a ajouté un besoin au chantier en cours', null, Need::SEARCHING, "Complément de {$parent} · {$reason}");
            $need->logActivity(NeedActivityCategory::Publication, 'a publié automatiquement les offres', null, 'Publié', "{$need->reference}/1", 'Système', 'Système');

            return $need;
        });

        Inertia::flash('toast', "Nouveau besoin {$need->reference} créé et publié · lancez le matching depuis « Postes et ouvriers manquants ».");

        return back();
    }
}
