<?php

namespace App\Support\Admin;

use App\Enums\RatingStatus;
use App\Enums\SiteStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Document;
use App\Models\Offer;
use App\Models\PromoterProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\Promoter\PromoterActivity;
use App\Support\Promoter\PromoterPayments;
use App\Support\Promoter\SiteManager;
use App\Support\Promoter\SiteRatings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Onglets en lecture seule de la fiche promoteur du Back Office : chantiers, offres, échéancier de paiement, avis et
 * litiges, journal d’activité. Les données sont celles du compte (tous ses chantiers, quel que soit le collaborateur).
 */
class PromoterRelations
{
    /**
     * Libellés des actions des administrateurs sur le promoteur ou ses pièces.
     */
    public const ADMIN_LABELS = [
        'promoter.created' => 'a créé le compte',
        'promoter.updated' => 'a modifié les informations du promoteur',
        'promoter.approved' => 'a validé le dossier',
        'promoter.corrections_requested' => 'a demandé des corrections',
        'promoter.rejected' => 'a refusé le dossier',
        'promoter.suspended' => 'a suspendu le compte',
        'promoter.reactivated' => 'a réactivé le compte',
        'promoter.messaged' => 'a envoyé un message',
        'document.uploaded' => 'a ajouté une pièce au dossier',
        'document.approved' => 'a validé une pièce',
        'document.rejected' => 'a rejeté une pièce',
    ];

    public function __construct(private SiteManager $sites, private PromoterPayments $payments, private SiteRatings $ratings) {}

    /**
     * Chantiers du compte (brouillons compris), les plus récents d’abord.
     *
     * @param  array{status?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Site>
     */
    public function sites(PromoterProfile $profile, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $profile->sites()
            ->with(['commune', 'neighborhood', 'constructionType', 'responsibleUser'])
            ->withCount(['offers', 'missions'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('label', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('reference', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('address', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('updated_at')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function site(Site $site): array
    {
        return [
            'id' => $site->id,
            'reference' => $site->reference,
            'title' => $site->label,
            'work_type' => $site->work_type ? ['value' => $site->work_type->value, 'label' => $site->work_type->label()] : null,
            'property_type' => $site->constructionType?->label,
            'address' => $site->address,
            'commune' => $site->commune?->name,
            'neighborhood' => $site->neighborhood?->name,
            'start_date' => $site->planned_start_date?->toDateString(),
            'end_date' => $site->planned_end_date?->toDateString(),
            'duration_days' => $site->planned_duration_days,
            'schedule' => $this->sites->schedule($site),
            'responsible' => $site->responsibleUser ? trim($site->responsibleUser->first_name.' '.$site->responsibleUser->last_name) : null,
            'offers_count' => (int) $site->offers_count,
            'missions_count' => (int) $site->missions_count,
            'status' => ['value' => $site->status->value, 'label' => $site->status->label()],
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Compteurs des chantiers par statut, pour les filtres de l’onglet.
     *
     * @return array<string, int>
     */
    public function siteCounts(PromoterProfile $profile): array
    {
        $counts = $profile->sites()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['all' => (int) $counts->sum(), ...collect(SiteStatus::cases())->mapWithKeys(fn (SiteStatus $status) => [$status->value => (int) $counts->get($status->value, 0)])->all()];
    }

    /**
     * Offres (réservations d’ouvriers) des chantiers du compte, avec leurs besoins.
     *
     * @return LengthAwarePaginator<int, Offer>
     */
    public function offers(PromoterProfile $profile, ?string $siteId, int $perPage = 20): LengthAwarePaginator
    {
        return Offer::query()
            ->whereIn('site_id', $profile->sites()->select('id'))
            ->when($siteId, fn (Builder $query, string $siteId) => $query->where('site_id', $siteId))
            ->with(['site', 'needs.trade'])
            ->withCount('proposals')
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function offer(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'reference' => $offer->reference,
            'title' => $offer->title,
            'type' => $offer->offer_type ? ['value' => $offer->offer_type->value, 'label' => $offer->offer_type->label()] : null,
            'site' => $offer->site ? ['id' => $offer->site->id, 'title' => $offer->site->label] : null,
            'needs' => $offer->needs->map(fn ($need) => [
                'id' => $need->id,
                'trade' => $need->trade?->label ?? $need->role_label,
                'headcount' => (int) $need->headcount,
                'status' => $need->status ? ['value' => $need->status->value, 'label' => $need->status->label()] : null,
            ])->values()->all(),
            'workers_count' => (int) $offer->needs->sum('headcount'),
            'proposals_count' => (int) $offer->proposals_count,
            'amount' => $offer->contract_total_amount ?? $offer->quote_amount,
            'start_date' => $offer->start_date?->toDateString(),
            'end_date' => $offer->end_date?->toDateString(),
            'published_at' => $offer->published_at?->toIso8601String(),
            'status' => ['value' => $offer->status->value, 'label' => $offer->status->label()],
            'created_at' => $offer->created_at?->toIso8601String(),
        ];
    }

    /**
     * Échéancier du compte, tel que le voit le titulaire dans l’application, avec ses totaux.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function payments(PromoterProfile $profile, ?string $siteId): array
    {
        $rows = $this->payments->rows($profile->user, $siteId)
            ->map(fn (array $row) => collect($row)->except(['target', 'can_pay'])->all());
        $sum = fn (array $states) => (int) $rows->whereIn('status.value', $states)->sum('amount');

        return [
            'data' => $rows->values()->all(),
            'meta' => [
                'totals' => ['a_regler' => $sum(['a_payer', 'en_retard']), 'en_retard' => $sum(['en_retard']), 'a_venir' => $sum(['a_venir']), 'payes' => $sum(['effectue', 'finalise'])],
                'counts' => ['all' => $rows->count(), ...collect(PromoterPayments::STATES)->map(fn (string $label, string $state) => $rows->where('status.value', $state)->count())->all()],
            ],
        ];
    }

    /**
     * Avis laissés sur les chantiers du compte (publiés ou masqués), avec moyenne et répartition.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function ratings(PromoterProfile $profile): array
    {
        return $this->ratings->forSites($profile->sites()->pluck('id'), [RatingStatus::Publie, RatingStatus::Masque]);
    }

    /**
     * Litiges ouverts sur les chantiers du compte, les plus récents d’abord.
     *
     * @return LengthAwarePaginator<int, Dispute>
     */
    public function disputes(PromoterProfile $profile, int $perPage = 20): LengthAwarePaginator
    {
        return Dispute::query()
            ->whereIn('site_id', $profile->sites()->select('id'))
            ->with(['site', 'assignedAdmin', 'openedBy'])
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function dispute(Dispute $dispute, PromoterProfile $profile): array
    {
        $opener = $dispute->openedBy;

        return [
            'id' => $dispute->id,
            'reference' => $dispute->reference,
            'category' => $dispute->category,
            'description' => $dispute->description,
            'site' => $dispute->site ? ['id' => $dispute->site->id, 'title' => $dispute->site->label] : null,
            'opened_by' => match (true) {
                $opener instanceof Admin => ['kind' => 'admin', 'name' => $opener->fullName()],
                $opener instanceof User => ['kind' => $opener->getKey() === $profile->user_id ? 'promoter' : 'user', 'name' => trim($opener->first_name.' '.$opener->last_name) ?: null],
                default => null,
            },
            'urgency' => $dispute->urgency ? ['value' => $dispute->urgency->value, 'label' => $dispute->urgency->label()] : null,
            'assigned_admin' => $dispute->assignedAdmin ? ['id' => $dispute->assignedAdmin->id, 'full_name' => $dispute->assignedAdmin->fullName()] : null,
            'status' => ['value' => $dispute->status->value, 'label' => $dispute->status->label()],
            'decided_at' => $dispute->decided_at?->toIso8601String(),
            'created_at' => $dispute->created_at?->toIso8601String(),
        ];
    }

    /**
     * Journal d’activité : actions des utilisateurs du compte dans l’application et des administrateurs sur le
     * promoteur ou ses pièces. $author : « admin », « promoter » ou null pour tout.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function activity(PromoterProfile $profile, ?string $author, int $perPage = 30): LengthAwarePaginator
    {
        $documents = Document::query()->where('owner_type', $profile->getMorphClass())->where('owner_id', $profile->getKey())->select('id');
        $admin = (new Admin)->getMorphClass();

        return AuditLog::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('subject_type', $profile->getMorphClass())->where('subject_id', $profile->getKey()))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', (new Document)->getMorphClass())->whereIn('subject_id', $documents)))
            ->when($author === 'admin', fn (Builder $query) => $query->where('actor_type', $admin))
            ->when($author === 'promoter', fn (Builder $query) => $query->where('actor_type', '!=', $admin))
            ->with('actor')
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function event(AuditLog $log): array
    {
        $actor = $log->actor;
        $values = $log->after_values ?? [];
        $isAdmin = $actor instanceof Admin || $log->actor_type === (new Admin)->getMorphClass();
        $at = CarbonImmutable::parse($log->created_at)->setTimezone(config('app.timezone'));

        return [
            'id' => $log->id,
            'action' => $log->action,
            'kind' => $isAdmin ? ['value' => 'admin', 'label' => 'Administrateur'] : ['value' => 'promoter', 'label' => 'Promoteur'],
            'author' => match (true) {
                $actor instanceof Admin => $actor->fullName(),
                $actor instanceof User => trim($actor->first_name.' '.$actor->last_name) ?: 'Utilisateur du compte',
                default => $isAdmin ? 'Administrateur supprimé' : 'Utilisateur supprimé',
            },
            'role' => $isAdmin ? null : ($values['role'] ?? null),
            'what' => $isAdmin
                ? (self::ADMIN_LABELS[$log->action] ?? $log->action)
                : ($log->action === 'login' ? 's’est connecté(e)' : (PromoterActivity::LABELS[$log->action] ?? $log->action)),
            'site' => $values['site'] ?? null,
            'detail' => $this->detail($log, $values),
            'at' => $at->toIso8601String(),
        ];
    }

    /**
     * Précision affichée sous l’action : motif, pièce, champs modifiés…
     *
     * @param  array<string, mixed>  $values
     */
    private function detail(AuditLog $log, array $values): ?string
    {
        $text = $values['detail'] ?? $values['reason'] ?? $values['decision_reason'] ?? $values['message'] ?? $values['subject'] ?? null;

        if ($text === null && $log->action === 'promoter.updated') {
            $count = count($values);
            $text = $count.' champ'.($count > 1 ? 's' : '').' modifié'.($count > 1 ? 's' : '');
        }

        if ($text === null && str_starts_with($log->action, 'document.')) {
            $text = $log->subject instanceof Document ? $log->subject->documentType?->label : ($values['document_type'] ?? null);
        }

        return is_string($text) ? $text : null;
    }
}
