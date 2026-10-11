<?php

namespace App\Support;

use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\PromoterOfferProfileStatus;
use App\Enums\WorkerStatus;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\NeedLine;
use App\Models\NeedOffer;
use App\Models\PromoterOffer;
use App\Models\PromoterOfferProfile;
use Illuminate\Support\Collection;

/**
 * Calculs du module Affectations & Offres : besoins suivis (ouvriers trouvés à contacter et valider),
 * offres des promoteurs (profils déjà choisis), acompte et échéancier, remplaçants proposés.
 *
 * Le taux d’acompte (X) vient des règles du module Besoins (config/admin.php › need_rules), reprises de Paramètres.
 */
class AssignmentInsights
{
    /**
     * Filtres des besoins suivis, dans l’ordre de la maquette.
     *
     * @var array<int, string>
     */
    public const NEED_FILTERS = ['Tous', 'À contacter', 'Partiellement couverts', 'Expirés (manquants)', 'Couverts · à valider', 'Acompte en attente', 'Comblés'];

    /**
     * Filtres des offres des promoteurs.
     *
     * @var array<int, string>
     */
    public const OFFER_FILTERS = ['Toutes', PromoterOffer::TO_PROCESS, PromoterOffer::REPLACEMENT, PromoterOffer::DEPOSIT, PromoterOffer::DONE];

    /**
     * Anciens libellés des liens (tableau de bord, notifications) : filtre → [liste, filtre appliqué].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const FILTER_ALIASES = ['À valider' => ['besoins', 'Couverts · à valider'], 'Remplacements' => ['offres', PromoterOffer::REPLACEMENT], 'Confirmées' => ['besoins', 'Comblés']];

    /**
     * Couleur des étapes d’une offre de promoteur.
     *
     * @var array<string, string>
     */
    public const OFFER_TONES = [PromoterOffer::TO_PROCESS => 'a', PromoterOffer::REPLACEMENT => 'r', PromoterOffer::TO_NOTIFY => 'b', PromoterOffer::DEPOSIT => 'o', PromoterOffer::DONE => 'g', PromoterOffer::CANCELLED => 'n'];

    /**
     * Ordre d’affichage des besoins suivis : d’abord ceux qui demandent une action.
     *
     * @var array<string, int>
     */
    private const NEED_ORDER = [Need::COVERED => 0, Need::DEPOSIT => 1, Need::PARTIAL => 3, Need::EXPIRED => 4, Need::DONE => 5];

    public const CHANNELS = ['Appel téléphonique', 'SMS', 'WhatsApp', 'Notification push', 'Visite terrain (commercial)'];

    public const PAYMENT_METHODS = ['Mobile Money', 'Virement bancaire', 'Chèque'];

    public const NOTIFICATION_CHANNELS = ['Push, SMS et e-mail', 'Notification push', 'SMS', 'E-mail'];

    /**
     * Motifs d’invalidation d’un ouvrier trouvé pour un besoin.
     *
     * @var array<int, string>
     */
    public const DECLINE_REASONS = ['L’ouvrier refuse le besoin', 'Indisponible sur la période', 'Rémunération insuffisante', 'Chantier trop éloigné', 'Injoignable', 'Autre'];

    /**
     * Motifs de refus d’un profil choisi par le promoteur ; les deux premiers viennent de l’ouvrier.
     *
     * @var array<int, string>
     */
    public const REFUSE_REASONS = ['L’ouvrier refuse l’offre', 'Injoignable', 'Profil non conforme au métier', 'Documents non vérifiés', 'Indisponible sur la période', 'Litige actif', 'Coût journalier hors budget', 'Autre'];

    public const REPLACEMENT_REASONS = ['Demande du promoteur', 'Absence prolongée', 'Qualité du travail', 'Blessure ou maladie', 'Autre'];

    public const URGENCY_ORDER = ['critical' => 0, 'urgent' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];

    public static function depositRate(): int
    {
        return (int) NeedInsights::rules()['deposit_rate'];
    }

    /**
     * Jours ouvrés (6 jours sur 7) d’une période.
     */
    public static function workingDays(int $duration): int
    {
        return max(1, (int) round($duration * 6 / 7));
    }

    /**
     * Acompte arrondi à 500 FCFA.
     */
    public static function depositOf(int $total): int
    {
        return self::roundTo500($total * self::depositRate() / 100);
    }

    private static function roundTo500(float $amount): int
    {
        return (int) round($amount / 500) * 500;
    }

    private static function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }

    /**
     * Coût global d’un besoin et échéancier : acompte initial, puis solde selon le mode de rémunération.
     *
     * @return array{mode: string, days: int, total: int, deposit: int, entries: array<int, array{label: string, amount: int, date: string, status: string}>}
     */
    public static function schedule(Need $need): array
    {
        $duration = $need->duration();
        $days = self::workingDays($duration);
        $mode = $need->lines->first()?->pay_type ?? 'Journalier';
        $total = (int) $need->lines->sum(fn (NeedLine $l): int => $l->quantity * $l->daily_cost * $days);
        $deposit = self::depositOf($total);
        $rest = $total - $deposit;
        $start = $need->starts_on->toImmutable();
        $entry = fn (string $label, int $amount, $date, string $status = 'À venir'): array => ['label' => $label, 'amount' => $amount, 'date' => $date->format('Y-m-d'), 'status' => $status];

        $entries = [$entry('Acompte initial ('.self::depositRate().' % · Paramètres)', $deposit, $need->status() === Need::DEPOSIT ? Need::referenceDate() : $start->subDays(2), $need->deposit_paid ? 'Payé' : 'En attente')];
        if ($mode === 'Hebdomadaire') {
            $weeks = max(1, (int) ceil($duration / 7));
            $part = self::roundTo500($rest / $weeks);
            for ($i = 0; $i < min($weeks, 6); $i++) {
                $label = 'Semaine '.($i + 1).($i === 5 && $weeks > 6 ? ' (puis '.($weeks - 6).' autres)' : '');
                $entries[] = $entry($label, $i === $weeks - 1 ? $rest - $part * ($weeks - 1) : $part, $start->addDays(7 * ($i + 1)));
            }
        } elseif ($mode === 'Journalier') {
            $entries[] = $entry('Solde à la fin de la mission (pointages validés)', $rest, $need->ends_on);
        } else {
            $half = self::roundTo500($rest / 2);
            $entries[] = $entry('Étape 1 · mi-parcours', $half, $start->addDays((int) round($duration / 2)));
            $entries[] = $entry('Solde · réception des travaux', $rest - $half, $need->ends_on);
        }

        return ['mode' => $mode, 'days' => $days, 'total' => $total, 'deposit' => $deposit, 'entries' => $entries];
    }

    /**
     * Besoins suivis dans Affectations & Offres : actifs, avec au moins un ouvrier trouvé.
     *
     * @return Collection<int, Need>
     */
    public static function trackedNeeds(): Collection
    {
        return Need::with(NeedInsights::RELATIONS)->whereNull('manual_status')->orderBy('id')->get()
            ->filter(fn (Need $n): bool => $n->coverage()['selected'] > 0)
            ->sortBy([fn (Need $a, Need $b): int => (self::NEED_ORDER[$a->status()] ?? 9) <=> (self::NEED_ORDER[$b->status()] ?? 9), fn (Need $a, Need $b): int => $a->starts_on <=> $b->starts_on])
            ->values();
    }

    /**
     * Filtres (hors « Tous ») auxquels appartient un besoin suivi.
     *
     * @return array<int, string>
     */
    public static function needFilters(Need $need): array
    {
        $status = $need->status();

        return array_keys(array_filter([
            'À contacter' => $need->coverage()['toContact'] > 0,
            'Partiellement couverts' => $status === Need::PARTIAL,
            'Expirés (manquants)' => $status === Need::EXPIRED,
            'Couverts · à valider' => $status === Need::COVERED,
            'Acompte en attente' => $status === Need::DEPOSIT,
            'Comblés' => in_array($status, [Need::DONE, Need::REPLACING], true),
        ]));
    }

    /**
     * Ligne du tableau « Besoins en cours d’affectation ».
     *
     * @return array<string, mixed>
     */
    public static function needRow(Need $need): array
    {
        $c = $need->coverage();
        $names = $need->assignments->pluck('worker_name')->implode(' ');
        $trades = $need->lines->map(fn (NeedLine $l): string => "{$l->quantity} ".mb_strtolower($l->trade))->implode(', ');

        return [
            'slug' => $need->slug,
            'reference' => $need->reference,
            'site' => $need->site->name,
            'location' => "{$need->site->commune} · {$need->site->type}",
            'promoter' => $need->site->promoter_name,
            'trades' => $trades,
            'selected' => $c['selected'],
            'need' => $c['need'],
            'percent' => min(100, $c['percent']),
            'rest' => $c['rest'],
            'toContact' => $c['toContact'],
            'steps' => "{$c['toContact']} à contacter · {$c['contacted']} contacté(s) · {$c['validated']} validé(s)",
            'startsOn' => $need->starts_on->format('Y-m-d'),
            'status' => NeedInsights::badge($need->status()),
            'payment' => $need->deposit_paid ? 'Acompte payé' : ($c['validated'] >= $c['need'] ? 'Acompte en attente' : '—'),
            'filters' => self::needFilters($need),
            'search' => mb_strtolower(implode(' ', [$need->reference, $need->site->name, $need->site->promoter_name, $need->site->commune, $need->lines->pluck('trade')->implode(' '), $names])),
        ];
    }

    /**
     * Offres des promoteurs : terminées en dernier, puis par urgence et de la plus récente à la plus ancienne.
     *
     * @return Collection<int, PromoterOffer>
     */
    public static function promoterOffers(): Collection
    {
        return PromoterOffer::with('site', 'profiles')->orderBy('id')->get()
            ->sortBy([
                fn (PromoterOffer $a, PromoterOffer $b): int => ($a->status() === PromoterOffer::DONE) <=> ($b->status() === PromoterOffer::DONE),
                fn (PromoterOffer $a, PromoterOffer $b): int => self::URGENCY_ORDER[$a->urgency->value] <=> self::URGENCY_ORDER[$b->urgency->value],
                fn (PromoterOffer $a, PromoterOffer $b): int => $b->sent_on <=> $a->sent_on,
            ])
            ->values();
    }

    /**
     * Ligne du tableau « Offres des promoteurs ».
     *
     * @return array<string, mixed>
     */
    public static function offerRow(PromoterOffer $offer): array
    {
        $k = $offer->counts();
        $status = $offer->status();
        $active = $offer->profiles->where('status', '!=', PromoterOfferProfileStatus::Replaced);
        $plural = fn (int $n): string => $n > 1 ? 's' : '';
        $progress = array_filter([
            $k['pending'] ? "{$k['pending']} à contacter" : '',
            $k['contacted'] ? "{$k['contacted']} contacté{$plural($k['contacted'])}" : '',
            $k['refused'] ? "{$k['refused']} refus" : '',
        ]);

        return [
            'slug' => $offer->slug,
            'reference' => $offer->reference,
            'sentOn' => $offer->sent_on->format('Y-m-d'),
            'promoter' => $offer->site->promoter_name,
            'site' => $offer->site->name,
            'location' => "{$offer->site->commune} · {$offer->site->type}",
            'trade' => $offer->trade,
            'post' => "{$k['total']} ouvrier{$plural($k['total'])} · début ".$offer->starts_on->format('d/m/Y'),
            'profiles' => $active->pluck('name')->implode(', '),
            'accepted' => $k['accepted'],
            'total' => $k['total'],
            'percent' => $k['total'] ? (int) round($k['accepted'] / $k['total'] * 100) : 0,
            'progress' => $progress ? implode(' · ', $progress) : 'Traitement terminé',
            'refused' => $k['refused'] > 0,
            'urgency' => ['label' => $offer->urgency->label(), 'tone' => $offer->urgency->tone()],
            'status' => ['label' => $status, 'tone' => self::OFFER_TONES[$status]],
            'canPay' => $status === PromoterOffer::DEPOSIT,
            'deposit' => $offer->deposit(),
            'search' => mb_strtolower(implode(' ', [$offer->reference, $offer->site->promoter_name, $offer->site->name, $offer->trade, $offer->site->commune, $offer->profiles->pluck('name')->implode(' ')])),
        ];
    }

    /**
     * Ouvriers déjà retenus ailleurs (besoins ou offres des promoteurs en cours).
     *
     * @return array<string, true>
     */
    public static function busyNames(): array
    {
        $assigned = NeedAssignment::query()->whereNotIn('status', [NeedAssignmentStatus::Refused, NeedAssignmentStatus::Replaced])->pluck('worker_name');
        $chosen = PromoterOfferProfile::query()
            ->whereIn('status', [PromoterOfferProfileStatus::Pending, PromoterOfferProfileStatus::Contacted, PromoterOfferProfileStatus::Accepted])
            ->whereHas('offer', fn ($q) => $q->where('cancelled', false))
            ->pluck('name');

        return $assigned->merge($chosen)->flip()->map(fn (): bool => true)->all();
    }

    /**
     * Remplaçants proposés pour un profil refusé d’une offre de promoteur : même métier, disponibles, non retenus ailleurs,
     * classés par score (expérience comparée au profil refusé, note, coût face au budget, disponibilité).
     *
     * @return array<int, array{p: array<string, mixed>, score: int, level: string}>
     */
    public static function promoterReplacements(PromoterOffer $offer, PromoterOfferProfile $refused, int $limit = 3): array
    {
        $config = MatchingEngine::config();
        $taken = $offer->profiles->pluck('name')->flip();
        $busy = self::busyNames();
        $needed = min(8, max(1, $refused->experience));
        $budget = max(1, $offer->daily_cost);

        return collect((new WorkerPool)->forTrade($offer->trade))
            ->reject(fn (array $p): bool => $taken->has($p['name']) || isset($busy[$p['name']]))
            ->reject(fn (array $p): bool => $p['status'] !== WorkerStatus::Validated->label() || in_array($p['availability'], ['En congé', 'Indisponible', 'En mission'], true)
                || $p['disputes'] > 0 || $p['cost'] > $budget * (1 + $config['tolerance_max'] / 100))
            ->map(function (array $p) use ($config, $needed, $budget): array {
                $cost = $p['cost'] <= $budget ? 100 : max(0, 100 - ($p['cost'] - $budget) / $budget * 400);
                $score = (int) round(0.3 * min(100, $p['experience'] / $needed * 100) + 0.25 * ($p['rating'] / 5 * 100) + 0.25 * $cost + 0.2 * ($p['availability'] === 'Disponible' ? 100 : 70));
                $level = collect($config['thresholds'])->search(fn (int $threshold): bool => $score >= $threshold) ?: 'Non recommandé';

                return ['p' => $p, 'score' => $score, 'level' => $level];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Besoins ajoutés par le promoteur pendant l’exécution (complément d’un besoin ou d’une offre).
     *
     * @return Collection<int, Need>
     */
    public static function children(string $reference): Collection
    {
        return Need::with(NeedInsights::RELATIONS)->where('parent_reference', $reference)
            ->where(fn ($q) => $q->whereNull('manual_status')->orWhereNotIn('manual_status', [NeedManualStatus::Cancelled, NeedManualStatus::Archived]))
            ->orderBy('id')->get();
    }

    /**
     * Postes et ouvriers manquants : une ligne par métier du besoin initial et des besoins ajoutés.
     *
     * @param  Collection<int, Need>  $children
     * @return array<int, array<string, mixed>>
     */
    public static function missingRows(?Need $need, Collection $children): array
    {
        $insights = new NeedInsights;
        $entries = collect($need ? [[$need, false]] : [])->merge($children->map(fn (Need $c): array => [$c, true]));

        return $entries->flatMap(fn (array $entry) => $entry[0]->lines->map(function (NeedLine $line) use ($entry, $insights): array {
            [$n, $child] = $entry;
            $offer = $n->offers->firstWhere('need_line_id', $line->id);
            $stats = $offer?->stats() ?? ['selected' => 0, 'validated' => 0];
            $rest = max(0, $line->quantity - $stats['selected']);
            $open = ! $n->manual_status;

            return [
                'key' => "{$n->id}-{$line->id}",
                'trade' => $line->trade,
                'sub' => ($line->specialty ? "{$line->specialty} · " : '').$line->experience.($child ? " · {$n->reference} · créé le ".$n->created_on->format('d/m/Y') : ''),
                'child' => $child,
                'needSlug' => $n->slug,
                'needReference' => $n->reference,
                'quantity' => $line->quantity,
                'selected' => $stats['selected'],
                'validated' => $stats['validated'],
                'rest' => $rest,
                'percent' => $line->quantity ? (int) round($stats['selected'] / $line->quantity * 100) : 0,
                'matchOffer' => $rest && $open && $offer?->isOpen() ? $offer->id : null,
                'expired' => $rest && $open && $offer?->status() === NeedOffer::EXPIRED ? NeedInsights::offerRef($offer) : null,
                'actionRef' => $insights->actionRef($n),
            ];
        }))->values()->all();
    }
}
