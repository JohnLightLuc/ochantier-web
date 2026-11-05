<?php

namespace App\Support\Admin;

use App\Enums\WorkerVerificationStatus;
use App\Models\AuditLog;
use App\Models\CommercialProfile;
use App\Models\Worker;
use App\Support\Commercial\CommercialPerformance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compléments de la fiche d’un commercial : rang du mois, évolution sur six mois, métiers recrutés.
 */
class CommercialStatistics
{
    public function __construct(private CommercialPerformance $performance) {}

    /**
     * Rang du mois parmi les commerciaux actifs (classement de l’application), null hors classement.
     *
     * @return array{rank: int, total: int, label: string}|null
     */
    public function ranking(CommercialProfile $commercial, string $status): ?array
    {
        if ($status !== 'actif') {
            return null;
        }

        $ranking = $this->performance->ranking($commercial);

        return $ranking['rank'] ? ['rank' => $ranking['rank'], 'total' => $ranking['total'], 'label' => $ranking['label']] : null;
    }

    /**
     * Ouvriers transmis et validés par mois, du plus ancien au plus récent.
     *
     * @return list<array{month: string, label: string, registered: int, validated: int}>
     */
    public function months(CommercialProfile $commercial, int $count = 6): array
    {
        $first = CarbonImmutable::today()->startOfMonth()->subMonths($count - 1);
        $workers = Worker::query()->where('origin_commercial_id', $commercial->getKey())->whereRaw(Worker::RECRUITED_SQL)->toBase();

        $registered = (clone $workers)->where('verification_status', '!=', WorkerVerificationStatus::Brouillon->value)->where('submitted_at', '>=', $first)
            ->selectRaw("to_char(submitted_at, 'YYYY-MM') as month, count(*) as total")->groupByRaw('1')->pluck('total', 'month');
        $validated = (clone $workers)->where('verification_status', WorkerVerificationStatus::Valide->value)->where('validated_at', '>=', $first)
            ->selectRaw("to_char(validated_at, 'YYYY-MM') as month, count(*) as total")->groupByRaw('1')->pluck('total', 'month');

        return collect(range(0, $count - 1))->map(function (int $offset) use ($first, $registered, $validated) {
            $month = $first->addMonths($offset);
            $key = $month->format('Y-m');

            return ['month' => $key, 'label' => mb_convert_case($month->locale('fr')->translatedFormat('M'), MB_CASE_TITLE), 'registered' => (int) $registered->get($key, 0), 'validated' => (int) $validated->get($key, 0)];
        })->all();
    }

    /**
     * Ouvriers transmis par métier principal, du plus fréquent au moins fréquent.
     *
     * @return list<array{label: string, count: int}>
     */
    public function trades(CommercialProfile $commercial): array
    {
        return DB::table('workers')
            ->join('worker_trades', fn ($join) => $join->on('worker_trades.worker_id', '=', 'workers.id')->where('worker_trades.is_primary', true))
            ->join('trades', 'trades.id', '=', 'worker_trades.trade_id')
            ->whereNull('workers.deleted_at')
            ->where('workers.origin_commercial_id', $commercial->getKey())
            ->whereRaw(Worker::RECRUITED_SQL)
            ->where('workers.verification_status', '!=', WorkerVerificationStatus::Brouillon->value)
            ->groupBy('trades.label')
            ->selectRaw('trades.label, count(*) as total')
            ->orderByDesc('total')->orderBy('trades.label')
            ->limit(6)
            ->get()
            ->map(fn (object $row) => ['label' => $row->label, 'count' => (int) $row->total])
            ->all();
    }

    public function terminationReason(CommercialProfile $commercial): ?string
    {
        return AuditLog::query()
            ->where('subject_type', $commercial->getMorphClass())
            ->where('subject_id', $commercial->getKey())
            ->where('action', 'commercial.terminated')
            ->latest()
            ->first()?->after_values['reason'] ?? null;
    }
}
