<?php

namespace App\Support\Admin;

use App\Enums\ProspectStatus;
use App\Models\Admin;
use App\Models\CommercialProfile;
use App\Models\Prospect;
use App\Models\Worker;
use App\Models\WorkerCommercialTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfert du suivi des ouvriers entre commerciaux. Le nouveau référent voit l’ouvrier, reçoit ses notifications et
 * corrige son dossier (le prospect d’origine le suit) ; commission, objectif et classement restent au recruteur.
 *
 * Un transfert de tout le portefeuille déplace aussi les prospects pas encore transmis : leur future commission
 * revient au nouveau commercial.
 */
class CommercialPortfolio
{
    /**
     * @param  list<string>|null  $workerIds  Null pour tout le portefeuille.
     * @return array{workers: int, prospects: int}
     */
    public function transfer(CommercialProfile $from, CommercialProfile $to, ?array $workerIds, string $reason, Admin $admin, Request $request): array
    {
        $workers = Worker::query()->where('current_commercial_id', $from->getKey())->when($workerIds, fn ($query, array $ids) => $query->whereKey($ids))->get();

        if ($workerIds !== null && $workers->count() !== count(array_unique($workerIds))) {
            throw ValidationException::withMessages(['worker_ids' => 'Certains ouvriers ne sont plus suivis par ce commercial.']);
        }

        $counts = DB::transaction(function () use ($from, $to, $workers, $workerIds, $reason, $admin): array {
            $workers->each(fn (Worker $worker) => $this->move($worker, $to->getKey(), $reason, $admin));

            $prospects = $workerIds === null
                ? Prospect::query()->where('commercial_id', $from->getKey())->whereNotIn('status', [ProspectStatus::Converti, ProspectStatus::Abandonne])->update(['commercial_id' => $to->getKey()])
                : 0;

            return ['workers' => $workers->count(), 'prospects' => $prospects];
        });

        AdminAudit::record($admin, 'commercials', 'commercial.portfolio_transferred', $request, $from, null, [...$counts, 'to_commercial_id' => $to->getKey(), 'reason' => $reason]);

        return $counts;
    }

    /**
     * Confie un ouvrier à un autre référent, avec son prospect d’origine.
     */
    public function move(Worker $worker, string $to, string $reason, Admin $admin): void
    {
        $from = $worker->current_commercial_id;

        if ($from === $to) {
            return;
        }

        if ($from) {
            WorkerCommercialTransfer::query()->create(['worker_id' => $worker->getKey(), 'from_commercial_id' => $from, 'to_commercial_id' => $to, 'reason' => $reason, 'transferred_by_admin_id' => $admin->getKey()]);
        }

        $worker->forceFill(['current_commercial_id' => $to])->save();

        if ($worker->prospect_id) {
            Prospect::query()->whereKey($worker->prospect_id)->update(['commercial_id' => $to]);
        }
    }
}
