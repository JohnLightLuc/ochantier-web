<?php

namespace App\Support\Pricing;

use App\Enums\ParameterStatus;
use App\Enums\PricingParameterScope;
use App\Enums\PricingVariable;
use App\Enums\WorkerVerificationStatus;
use App\Models\PricingParameter;
use App\Models\Setting;
use App\Models\Trade;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres financiers en vigueur (acompte X, commission ouvrier Z…) et tarifs de référence des métiers.
 */
class PricingRules
{
    /**
     * Valeurs par défaut si un paramètre n’est pas encore saisi par le Back office.
     *
     * @var array<string, float>
     */
    private const DEFAULTS = ['X' => 30, 'Y' => 8, 'Z' => 10, 'R' => 50];

    /**
     * Nombre d’ouvriers validés à partir duquel le tarif moyen constaté remplace le tarif de référence.
     */
    public const MIN_WORKERS_FOR_AVERAGE = 3;

    /**
     * Valeur globale en vigueur d’un paramètre (en %).
     */
    public function value(PricingVariable $variable): float
    {
        return once(fn () => (float) (PricingParameter::query()
            ->where('code', $variable)
            ->where('scope', PricingParameterScope::Global)
            ->where('status', ParameterStatus::Actif)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->latest('effective_from')
            ->value('value') ?? self::DEFAULTS[$variable->value]));
    }

    /**
     * Tarif journalier affiché au promoteur : tarif net déclaré par l’ouvrier augmenté de la commission Z, arrondi
     * à la centaine.
     */
    public function promoterDailyRate(?int $netRate): ?int
    {
        return $netRate ? (int) (round($netRate * (1 + $this->value(PricingVariable::Z) / 100) / 100) * 100) : null;
    }

    /**
     * Acompte (X) d’un montant, arrondi au millier.
     */
    public function deposit(int $amount): int
    {
        return (int) (round($amount * $this->value(PricingVariable::X) / 100 / 1000) * 1000);
    }

    /**
     * Tarif moyen constaté par métier (tarif promoteur des ouvriers validés dont c’est le métier principal), ou
     * tarif de référence du Back office tant qu’il y a moins de MIN_WORKERS_FOR_AVERAGE ouvriers.
     *
     * @return Collection<string, array{rate: int|null, source: string}> par identifiant de métier
     */
    public function referenceRates(): Collection
    {
        return once(function (): Collection {
            $references = (array) (Setting::query()->whereKey('pricing.reference_daily_rates')->value('value') ?? []);
            $averages = DB::table('worker_trades')
                ->join('workers', 'workers.id', '=', 'worker_trades.worker_id')
                ->where('worker_trades.is_primary', true)
                ->where('workers.verification_status', WorkerVerificationStatus::Valide->value)
                ->whereNull('workers.deleted_at')
                ->whereNotNull('workers.estimated_daily_rate')
                ->groupBy('worker_trades.trade_id')
                ->selectRaw('worker_trades.trade_id, count(*) as workers, avg(workers.estimated_daily_rate) as average')
                ->get()
                ->keyBy('trade_id');

            return Trade::query()->get(['id', 'code'])->mapWithKeys(function (Trade $trade) use ($averages, $references): array {
                $average = $averages->get($trade->id);

                return [$trade->id => $average && $average->workers >= self::MIN_WORKERS_FOR_AVERAGE
                    ? ['rate' => $this->promoterDailyRate((int) round($average->average)), 'source' => 'moyenne']
                    : ['rate' => isset($references[$trade->code]) ? (int) $references[$trade->code] : null, 'source' => 'reference']];
            });
        });
    }
}
