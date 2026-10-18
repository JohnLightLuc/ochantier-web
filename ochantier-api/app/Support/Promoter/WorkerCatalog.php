<?php

namespace App\Support\Promoter;

use App\Enums\AvailabilityStatus;
use App\Enums\PricingVariable;
use App\Enums\WorkerVerificationStatus;
use App\Models\Worker;
use App\Support\Pricing\PricingRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ouvriers proposés au promoteur (« Choisir des ouvriers ») : ouvriers validés, sans leurs coordonnées.
 */
class WorkerCatalog
{
    public const WITH = ['photoMedia', 'residenceCommune', 'workerTrades.trade'];

    public function __construct(private PricingRules $pricing) {}

    /**
     * Ouvriers validés, disponibles en premier puis par note. Filtres : trade_id (un des métiers de l’ouvrier),
     * search (nom ou métier), max_rate (tarif promoteur maximum), available (disponibles uniquement).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Worker>
     */
    public function query(array $filters = []): Builder
    {
        $z = $this->pricing->value(PricingVariable::Z);

        return Worker::query()
            ->with(self::WITH)
            ->where('verification_status', WorkerVerificationStatus::Valide)
            ->whereNull('merged_into_worker_id')
            ->when($filters['trade_id'] ?? null, fn (Builder $query, string $trade) => $query->whereHas('workerTrades', fn (Builder $query) => $query->where('trade_id', $trade)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw("(first_name || ' ' || last_name) ILIKE ?", [$like])
                    ->orWhereHas('workerTrades.trade', fn (Builder $query) => $query->where('label', 'ilike', $like)));
            })
            ->when($filters['max_rate'] ?? null, fn (Builder $query, int $max) => $query->where('estimated_daily_rate', '<=', (int) floor($max / (1 + $z / 100))))
            ->when($filters['available'] ?? false, fn (Builder $query) => $query->where('availability_status', AvailabilityStatus::Disponible))
            ->orderByRaw("availability_status = 'disponible' DESC")
            ->orderByRaw('rating_avg DESC NULLS LAST')
            ->orderBy('last_name');
    }

    /**
     * Ouvriers validés parmi les identifiants donnés (ouvriers choisis dans un brouillon).
     *
     * @param  list<string>  $ids
     * @return Collection<int, Worker>
     */
    public function find(array $ids): Collection
    {
        return $ids === [] ? collect() : $this->query()->whereKey($ids)->get();
    }

    public function promoterRate(Worker $worker): ?int
    {
        return $this->pricing->promoterDailyRate($worker->estimated_daily_rate);
    }
}
