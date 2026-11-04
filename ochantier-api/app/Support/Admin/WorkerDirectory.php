<?php

namespace App\Support\Admin;

use App\Enums\AvailabilityStatus;
use App\Enums\DocumentVerificationStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Commune;
use App\Models\Trade;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Ouvriers du Back Office : dossiers transmis par les commerciaux et ouvriers enregistrés par le Back Office (même
 * en brouillon ; les brouillons des commerciaux restent côté commercial), filtrés, triés et comptés.
 */
class WorkerDirectory
{
    public const STATUSES = ['brouillon', 'soumis', 'en_controle', 'a_corriger', 'valide', 'refuse', 'suspendu', 'exclu'];

    /**
     * Listes « à traiter » : dossiers à vérifier, renvoyés au commercial, avec une pièce expirée.
     */
    public const TODO = ['a_verifier' => 'À vérifier', 'a_corriger' => 'À corriger', 'pieces_expirees' => 'Pièces expirées'];

    /**
     * Niveau => années d’expérience dans le métier principal [min, max].
     */
    public const LEVELS = ['debutant' => [0, 2], 'confirme' => [3, 6], 'expert' => [7, null]];

    public const SORTS = ['name' => 'workers.last_name', 'experience' => 'experience_years', 'daily_rate' => 'workers.estimated_daily_rate', 'rating' => 'workers.rating_avg', 'status' => 'workers.verification_status', 'submitted_at' => 'workers.submitted_at'];

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (WorkerIndexRequest).
     * @return Builder<Worker>
     */
    public function query(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'submitted_at';
        $direction = ($filters['direction'] ?? ($sort === 'submitted_at' ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';

        return $this->filtered($filters)
            ->select('workers.*')
            ->selectSub(fn (QueryBuilder $query) => $this->primaryYears($query), 'experience_years')
            ->selectSub(fn (QueryBuilder $query) => $this->expiredDocuments($query->selectRaw('count(*)')), 'expired_documents_count')
            ->with(['workerTrades.trade', 'residenceCommune', 'currentCommercial.user', 'photoMedia'])
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('workers.id');
    }

    /**
     * Compteurs par statut, par disponibilité (ouvriers validés) et par liste « à traiter ».
     *
     * @return array{total: int, statuses: array<string, int>, availability: array<string, int>, todo: array<string, int>}
     */
    public function counts(): array
    {
        $statuses = $this->visible(Worker::query())->toBase()->selectRaw('verification_status, count(*) as total')->groupBy('verification_status')->pluck('total', 'verification_status');
        $availability = Worker::query()->where('verification_status', WorkerVerificationStatus::Valide)->toBase()->selectRaw('availability_status, count(*) as total')->groupBy('availability_status')->pluck('total', 'availability_status');

        return [
            'total' => (int) $statuses->sum(),
            'statuses' => collect(self::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) $statuses->get($status, 0)])->all(),
            'availability' => collect(AvailabilityStatus::cases())->mapWithKeys(fn (AvailabilityStatus $status) => [$status->value => (int) $availability->get($status->value, 0)])->all(),
            'todo' => collect(array_keys(self::TODO))->mapWithKeys(fn (string $list) => [$list => $this->filtered(['todo' => $list])->count()])->all(),
        ];
    }

    /**
     * Métiers et communes présents parmi les ouvriers (listes des filtres).
     *
     * @return array{trades: list<array{id: string, label: string}>, communes: list<array{id: string, name: string}>}
     */
    public function options(): array
    {
        $workers = $this->visible(Worker::query());

        return [
            'trades' => Trade::query()->whereIn('id', fn (QueryBuilder $query) => $query->select('trade_id')->from('worker_trades')->whereIn('worker_id', (clone $workers)->select('id')))
                ->orderBy('label')->get(['id', 'label'])->map(fn (Trade $trade) => ['id' => $trade->id, 'label' => $trade->label])->all(),
            'communes' => Commune::query()->whereIn('id', (clone $workers)->whereNotNull('residence_commune_id')->select('residence_commune_id'))
                ->orderBy('name')->get(['id', 'name'])->map(fn (Commune $commune) => ['id' => $commune->id, 'name' => $commune->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Worker>
     */
    private function filtered(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $level = isset($filters['level']) ? self::LEVELS[$filters['level']] : null;

        return $this->visible(Worker::query())
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('workers.verification_status', $status))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('first_name', 'ilike', $search)
                ->orWhere('last_name', 'ilike', $search)
                ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search])
                ->orWhere('reference', 'ilike', $search)
                ->orWhere('phone_number', 'ilike', str_replace(' ', '', $search))))
            ->when($filters['trade_id'] ?? null, fn (Builder $query, string $trade) => $query->whereHas('workerTrades', fn (Builder $query) => $query->where('trade_id', $trade)))
            ->when($level, fn (Builder $query) => $query->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('worker_trades')
                ->whereColumn('worker_trades.worker_id', 'workers.id')->where('is_primary', true)
                ->where('years_experience', '>=', $level[0])
                ->when($level[1] !== null, fn (QueryBuilder $query) => $query->where('years_experience', '<=', $level[1]))))
            ->when($filters['availability'] ?? null, fn (Builder $query, string $availability) => $query->where('availability_status', $availability))
            ->when($filters['commune_id'] ?? null, fn (Builder $query, string $commune) => $query->where('residence_commune_id', $commune))
            ->when($filters['commercial_id'] ?? null, fn (Builder $query, string $commercial) => $query->where('current_commercial_id', $commercial))
            ->when(isset($filters['daily_rate_min']), fn (Builder $query) => $query->where('estimated_daily_rate', '>=', (int) $filters['daily_rate_min']))
            ->when(isset($filters['daily_rate_max']), fn (Builder $query) => $query->where('estimated_daily_rate', '<=', (int) $filters['daily_rate_max']))
            ->when(isset($filters['rating_min']), fn (Builder $query) => $query->where('rating_avg', '>=', (float) $filters['rating_min']))
            ->when(isset($filters['rating_max']), fn (Builder $query) => $query->where('rating_avg', '<', (float) $filters['rating_max']))
            ->when($filters['todo'] ?? null, fn (Builder $query, string $list) => match ($list) {
                'a_verifier' => $query->whereIn('workers.verification_status', WorkerVerification::REVIEWABLE),
                'a_corriger' => $query->where('workers.verification_status', WorkerVerificationStatus::ACorriger),
                'pieces_expirees' => $query->whereExists(fn (QueryBuilder $query) => $this->expiredDocuments($query->selectRaw('1'))),
            });
    }

    /**
     * Ouvriers visibles du Back Office : tout sauf les brouillons des commerciaux.
     *
     * @param  Builder<Worker>  $query
     * @return Builder<Worker>
     */
    private function visible(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('workers.verification_status', '!=', WorkerVerificationStatus::Brouillon)
            ->orWhere('workers.acquisition_channel', Worker::BACK_OFFICE_CHANNEL));
    }

    private function primaryYears(QueryBuilder $query): QueryBuilder
    {
        return $query->select('years_experience')->from('worker_trades')->whereColumn('worker_trades.worker_id', 'workers.id')->where('is_primary', true)->limit(1);
    }

    /**
     * Pièces en vigueur expirées de l’ouvrier.
     */
    private function expiredDocuments(QueryBuilder $query): QueryBuilder
    {
        return $query->from('documents')
            ->whereNull('documents.deleted_at')
            ->where('documents.owner_type', (new Worker)->getMorphClass())
            ->whereColumn('documents.owner_id', 'workers.id')
            ->where('documents.verification_status', '!=', DocumentVerificationStatus::Remplace->value)
            ->where(fn (QueryBuilder $query) => $query->where('documents.verification_status', DocumentVerificationStatus::Expire->value)->orWhere('documents.expires_at', '<', today()));
    }
}
