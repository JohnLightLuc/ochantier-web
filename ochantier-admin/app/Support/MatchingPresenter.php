<?php

namespace App\Support;

use App\Models\Need;
use App\Models\NeedOffer;

/**
 * Mise en forme des données du Matching pour les pages : profils, offres et sélections.
 */
class MatchingPresenter
{
    public function __construct(private MatchingEngine $engine) {}

    /**
     * @return array{label: string, tone: string}
     */
    public static function status(string $label): array
    {
        return ['label' => $label, 'tone' => MatchingEngine::STATUS_TONES[$label] ?? 'n'];
    }

    /**
     * @return array{label: string, tone: string}
     */
    public static function level(string $label): array
    {
        return ['label' => $label, 'tone' => MatchingEngine::LEVEL_TONES[$label] ?? 'n'];
    }

    /**
     * Profil recommandé (ou exclu) pour une offre, avec son score détaillé et son statut.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function profile(NeedOffer $offer, array $item): array
    {
        $p = $item['p'];
        $sc = $item['sc'];
        $selection = $this->engine->selection($offer);
        $derogation = $this->engine->derogation($offer, $p['key']);
        $rejection = $this->engine->rejection($offer, $p['key']);

        return [
            'key' => $p['key'],
            'matchId' => $item['id'],
            'offerId' => $offer->id,
            'name' => $p['name'],
            'workerSlug' => $p['slug'],
            'code' => $p['id'],
            'trade' => $p['trade'],
            'specialty' => $p['specialty'],
            'commune' => $p['commune'],
            'km' => $p['km'],
            'minutes' => $p['minutes'],
            'experience' => $p['experience'],
            'cost' => $p['cost'],
            'rating' => $p['rating'],
            'reviews' => $p['missions'],
            'missions' => $p['missions'],
            'availability' => $p['availability'],
            'availableOn' => $p['availableOn'],
            'verified' => $p['verified'],
            'dossier' => $p['dossier'],
            'certs' => $p['certs'],
            'conflict' => $p['conflict'],
            'acceptance' => $p['acceptance'],
            'presence' => $p['presence'],
            'incidents' => $p['incidents'],
            'newcomer' => $p['newcomer'],
            'former' => $p['former'],
            'previous' => $p['previous'],
            'lastActivity' => $p['lastActivity'],
            'locatedOn' => $p['locatedOn'],
            'locationReliability' => $p['locationReliability'],
            'score' => $sc['score'],
            'raw' => $sc['raw'],
            'level' => self::level($sc['level']),
            'blocked' => $sc['blocked'],
            'absolute' => collect($sc['blocking'])->contains('absolute', true),
            'blocking' => $sc['blocking'],
            'warnings' => $sc['warnings'],
            'criteria' => $sc['criteria'],
            'computedOn' => $sc['date'],
            'computedBy' => $sc['by'],
            'status' => self::status($this->engine->recommendationStatus($offer, $item)),
            'selected' => in_array($p['key'], $selection['main'], true),
            'replacement' => $selection['replacements'][$p['key']] ?? null,
            'derogation' => $derogation?->justification,
            'rejection' => $rejection ? "{$rejection->reason} · {$rejection->nature}".($rejection->comment ? " · {$rejection->comment}" : '').($rejection->corrective_action ? " · action : {$rejection->corrective_action}" : '') : null,
        ];
    }

    /**
     * Offre (poste d’un besoin) vue par le Matching.
     *
     * @return array<string, mixed>
     */
    public function offer(NeedOffer $offer): array
    {
        $need = $offer->need;
        $line = $offer->line;
        $stats = $offer->stats();
        $status = $this->engine->offerStatus($offer);

        return [
            'id' => $offer->id,
            'reference' => $offer->reference,
            'need' => ['slug' => $need->slug, 'reference' => $need->reference, 'admin' => $need->admin, 'requirements' => $need->requirements, 'district' => $need->district],
            'site' => ['name' => $need->site->name, 'slug' => $need->site->slug, 'type' => $need->site->type, 'commune' => $need->site->commune, 'city' => $need->site->city, 'promoter' => $need->site->promoter_name],
            'trade' => $line->trade,
            'specialty' => $line->specialty,
            'experience' => $line->experience,
            'quantity' => $line->quantity,
            'selected' => $stats['selected'],
            'rest' => $stats['rest'],
            'coverage' => Need::coverageLabel($stats['percent']),
            'startsOn' => $offer->starts_on->format('Y-m-d'),
            'endsOn' => $offer->ends_on->format('Y-m-d'),
            'deadline' => $offer->deadline->format('Y-m-d'),
            'duration' => (int) $offer->starts_on->diffInDays($offer->ends_on) + 1,
            'workingHours' => $need->working_hours,
            'cost' => $line->daily_cost,
            'payType' => $line->pay_type,
            'negotiable' => ($need->details['negotiation'] ?? null) === 'Négociable',
            'urgency' => ['value' => $offer->urgency->value, 'label' => $offer->urgency->label(), 'tone' => $offer->urgency->tone()],
            'status' => self::status($status),
            'eligible' => $this->engine->isEligible($offer),
            'obsolete' => $this->engine->isObsolete($offer),
            'lastRun' => $offer->last_matched_on ? ['date' => $offer->last_matched_on->format('Y-m-d'), 'by' => $offer->last_matched_by, 'cycle' => $offer->last_cycle] : null,
        ];
    }

    /**
     * Sélection d’une offre, ses profils retenus, ses remplaçants et son budget.
     *
     * @return array<string, mixed>
     */
    public function selection(NeedOffer $offer): array
    {
        $selection = $this->engine->selection($offer);
        $profile = fn (string $key): ?array => ($item = $this->engine->item($offer, $key)) ? $this->profile($offer, $item) : null;

        return [
            'status' => self::status($selection['status']),
            'author' => $selection['author'],
            'updatedOn' => $selection['updatedOn'],
            'note' => $selection['note'],
            'persisted' => $selection['persisted'],
            'main' => array_values(array_filter(array_map($profile, $selection['main']))),
            'replacements' => collect($selection['replacements'])->map(fn (string $role, string $key): ?array => ($p = $profile($key)) ? [...$p, 'role' => $role] : null)->filter()->values()->all(),
            'budget' => $this->engine->budget($offer, $selection['main']),
        ];
    }
}
