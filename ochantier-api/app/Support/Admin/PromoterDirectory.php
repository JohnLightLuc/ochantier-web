<?php

namespace App\Support\Admin;

use App\Enums\DisputeStatus;
use App\Enums\DocumentVerificationStatus;
use App\Enums\PromoterVerificationStatus;
use App\Enums\UserStatus;
use App\Models\PromoterProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Liste des promoteurs du Back Office : recherche, filtres, tri, compteurs et liste « à traiter ».
 *
 * Le statut affiché est le statut de vérification du dossier, sauf pour un compte suspendu (statut du compte).
 */
class PromoterDirectory
{
    /**
     * Statuts filtrables : statuts de vérification et « suspendu » (compte).
     */
    public const STATUSES = ['brouillon', 'soumis', 'en_controle', 'a_corriger', 'valide', 'refuse', 'suspendu'];

    /**
     * Listes « à traiter » : dossiers à vérifier, incomplets, avec une pièce expirée, signalés (litige ouvert).
     *
     * @var array<string, string>
     */
    public const TODO = ['a_verifier' => 'À vérifier', 'incomplets' => 'Incomplets', 'pieces_expirees' => 'Pièces expirées', 'signales' => 'Signalés'];

    /**
     * Tri => colonne.
     *
     * @var array<string, string>
     */
    public const SORTS = ['name' => 'users.last_name', 'registered_at' => 'promoter_profiles.created_at', 'status' => 'promoter_profiles.verification_status'];

    private const OPEN_DISPUTES = [DisputeStatus::Ouvert, DisputeStatus::EnInstruction, DisputeStatus::EnRecours];

    /**
     * Requête filtrée et triée, avec les compteurs de pièces expirées et de litiges ouverts de chaque promoteur.
     *
     * @param  array<string, mixed>  $filters  Filtres validés (PromoterIndexRequest).
     * @return Builder<PromoterProfile>
     */
    public function query(array $filters): Builder
    {
        $column = self::SORTS[$filters['sort'] ?? 'registered_at'];
        $direction = ($filters['direction'] ?? ($column === self::SORTS['registered_at'] ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';

        return $this->filtered($filters)
            ->select('promoter_profiles.*')
            ->join('users', 'users.id', '=', 'promoter_profiles.user_id')
            ->selectSub(fn (QueryBuilder $query) => $this->expiredDocuments($query->selectRaw('count(*)')), 'expired_documents_count')
            ->selectSub(fn (QueryBuilder $query) => $this->openDisputes($query->selectRaw('count(*)')), 'open_disputes_count')
            ->with(['user.ownedOrganizations', 'commune'])
            ->orderBy($column, $direction)
            ->orderBy('promoter_profiles.id');
    }

    /**
     * Compteurs par statut et par liste « à traiter », sur l’ensemble des promoteurs.
     *
     * @return array{total: int, statuses: array<string, int>, todo: array<string, int>}
     */
    public function counts(): array
    {
        $statuses = [];

        foreach (self::STATUSES as $status) {
            $statuses[$status] = $this->filtered(['status' => $status])->count();
        }

        $todo = [];

        foreach (array_keys(self::TODO) as $list) {
            $todo[$list] = $this->filtered(['todo' => $list])->count();
        }

        return ['total' => $this->filtered([])->count(), 'statuses' => $statuses, 'todo' => $todo];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PromoterProfile>
     */
    private function filtered(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return PromoterProfile::query()
            ->whereHas('user')
            ->when($search, fn (Builder $query) => $query->whereHas('user', fn (Builder $query) => $query
                ->where('first_name', 'ilike', $search)
                ->orWhere('last_name', 'ilike', $search)
                ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search])
                ->orWhere('email', 'ilike', $search)
                ->orWhere('phone_number', 'ilike', str_replace(' ', '', $search))
                ->orWhereHas('ownedOrganizations', fn (Builder $query) => $query->where('legal_name', 'ilike', $search)->orWhere('trade_name', 'ilike', $search)->orWhere('rccm', 'ilike', $search))))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $status === 'suspendu'
                ? $query->whereHas('user', fn (Builder $query) => $query->where('status', UserStatus::Suspendu))
                : $query->where('promoter_profiles.verification_status', $status)->whereHas('user', fn (Builder $query) => $query->where('status', '!=', UserStatus::Suspendu)))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('profile_kind', $kind))
            ->when($filters['commune_id'] ?? null, fn (Builder $query, string $commune) => $query->where('promoter_profiles.commune_id', $commune))
            ->when($filters['registered'] ?? null, fn (Builder $query, string $period) => $period === '30j'
                ? $query->where('promoter_profiles.created_at', '>=', now()->subDays(30)->startOfDay())
                : $query->whereYear('promoter_profiles.created_at', (int) $period))
            ->when($filters['todo'] ?? null, fn (Builder $query, string $list) => match ($list) {
                'a_verifier' => $query->whereIn('promoter_profiles.verification_status', PromoterVerification::REVIEWABLE),
                'incomplets' => $query->whereIn('promoter_profiles.verification_status', [PromoterVerificationStatus::Brouillon, PromoterVerificationStatus::ACorriger]),
                'pieces_expirees' => $query->whereExists(fn (QueryBuilder $query) => $this->expiredDocuments($query->selectRaw('1'))),
                'signales' => $query->whereExists(fn (QueryBuilder $query) => $this->openDisputes($query->selectRaw('1'))),
            });
    }

    /**
     * Pièces en vigueur expirées du promoteur ou de son entreprise.
     */
    private function expiredDocuments(QueryBuilder $query): QueryBuilder
    {
        return $query->from('documents')
            ->whereNull('documents.deleted_at')
            ->where('documents.verification_status', '!=', DocumentVerificationStatus::Remplace->value)
            ->where(fn (QueryBuilder $query) => $query->where('documents.verification_status', DocumentVerificationStatus::Expire->value)->orWhere('documents.expires_at', '<', today()))
            ->where(fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $query) => $query->where('documents.owner_type', 'promoter_profile')->whereColumn('documents.owner_id', 'promoter_profiles.id'))
                ->orWhere(fn (QueryBuilder $query) => $query->where('documents.owner_type', 'organization')->whereIn('documents.owner_id', fn (QueryBuilder $query) => $query
                    ->select('organizations.id')->from('organizations')->whereColumn('organizations.owner_user_id', 'promoter_profiles.user_id')->whereNull('organizations.deleted_at'))));
    }

    /**
     * Litiges ouverts sur les chantiers du promoteur.
     */
    private function openDisputes(QueryBuilder $query): QueryBuilder
    {
        return $query->from('disputes')
            ->whereIn('disputes.status', array_map(fn (DisputeStatus $status) => $status->value, self::OPEN_DISPUTES))
            ->whereIn('disputes.site_id', fn (QueryBuilder $query) => $query->select('sites.id')->from('sites')->whereColumn('sites.promoter_profile_id', 'promoter_profiles.id'));
    }
}
