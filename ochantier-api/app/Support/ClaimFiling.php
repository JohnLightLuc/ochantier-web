<?php

namespace App\Support;

use App\Models\Accounts\PromoterAccount;
use App\Models\Accounts\WorkerAccount;
use App\Models\Attendance;
use App\Models\Claim;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Dépôt d’une réclamation depuis une application, sur une opération (paiement) ou une journée de pointage.
 * La réclamation arrive « Reçue » dans le module Avis & litiges du Back-office.
 */
class ClaimFiling
{
    /**
     * @param  array{category: string, subject: string, description: string, priority?: string|null}  $data
     */
    public function file(WorkerAccount|PromoterAccount $author, Payment|Attendance $subject, array $data): Claim
    {
        $subject->loadMissing('site', 'worker');
        $site = $subject->site;
        $worker = $subject->worker;
        $promoterParty = ['name' => ($site->manager ?: $site->promoter_name).' · '.$site->promoter_name, 'role' => 'Promoteur', 'slug' => $site->promoter?->slug];
        $workerParty = $worker ? ['name' => $worker->name, 'role' => 'Ouvrier', 'slug' => $worker->slug] : ['name' => '—', 'role' => 'Ouvrier', 'slug' => null];
        $isPromoter = $author instanceof PromoterAccount;

        if ($isPromoter) {
            $promoterParty['name'] = "{$author->representative} · {$author->name}";
        }

        return DB::transaction(function () use ($author, $subject, $data, $site, $promoterParty, $workerParty, $isPromoter): Claim {
            $today = today();
            $claim = Claim::create([
                'reference' => 'REC-'.$today->year.'-'.sprintf('%04d', 300 + (int) Claim::query()->max('id') + 1),
                'claimant' => $isPromoter ? $promoterParty : $workerParty,
                'other_party' => $isPromoter ? $workerParty : $promoterParty,
                'site_id' => $site->id,
                'assignment_reference' => $subject->assignment_reference ?? '—',
                'operation_reference' => $subject->reference,
                'category' => $data['category'],
                'subject' => $data['subject'],
                'description' => $data['description'],
                'date' => $today,
                'priority' => $data['priority'] ?? 'Normale',
                'owner' => config('admin.quality.owners')[0],
                'due_date' => $today->copy()->addDays(config('admin.quality.sla.qualif')),
                'status' => 'Reçue',
            ]);
            $claim->log('reclamation', 'a déposé la réclamation', null, 'Reçue', $data['subject'], $author->name, $author->origin());

            return $claim;
        });
    }

}
