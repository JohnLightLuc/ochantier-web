<?php

namespace Database\Seeders;

use App\Enums\WorkerStatus;
use App\Models\Attendance;
use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Models\Site;
use App\Models\Worker;
use App\Support\PaymentLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Opérations financières de la maquette :
 * - une opération par ouvrier, chantier et semaine de pointage (journées clôturées × tarif de l’ouvrier) ;
 * - dix semaines archivées par ouvrier validé, avec primes, indemnités et avances ;
 * - cinq acomptes de chantier (commission entièrement supportée par le promoteur) ;
 * - cas particuliers : remboursement partiel effectué, remboursement total demandé, écart d’encaissement
 *   et référence externe dupliquée.
 *
 * Les prélèvements promoteurs et la validation des calculs sont automatiques : les calculs restants sont validés
 * et les paiements non initiés passent « En attente ».
 */
class PaymentSeeder extends Seeder
{
    private const METHODS = ['Orange Money', 'MTN Mobile Money', 'Moov Money', 'Wave', 'Carte bancaire', 'Virement bancaire', 'Dépôt manuel rapproché'];

    private const WORKER_METHODS = ['Orange Money', 'Wave', 'MTN Mobile Money'];

    private const DEPOSITS = [['residence-les-oliviers', 2500000, -40], ['tour-horizon', 4000000, -33], ['cite-belle-rive', 1800000, -20], ['complexe-scolaire-sa', 3200000, -12], ['logements-sociaux-yopougon', 1500000, -3]];

    private int $number = 5000;

    private CarbonImmutable $today;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->today = Payment::referenceDate();
        $workers = Worker::query()->get()->keyBy('id');
        $attendances = Attendance::query()->whereDate('date', '<=', $this->today)->orderBy('id')->get();

        $attendances->groupBy(fn (Attendance $p): string => $p->worker_id.'|'.$p->site_id.'|'.$p->date->toImmutable()->startOfWeek()->format('Y-m-d'))
            ->each(fn (Collection $list, string $key) => $this->fromAttendances($list, $key, $workers[$list->first()->worker_id]));

        $first = $attendances->min('date');
        Worker::query()->where('status', WorkerStatus::Validated)->orderBy('id')->get()->each(function (Worker $w, int $wi) use ($attendances, $first): void {
            $site = $attendances->firstWhere('worker_id', $w->id)?->site_id;
            if ($site) {
                $this->archived($w, $wi, $site, CarbonImmutable::parse($first)->startOfWeek());
            }
        });

        $sites = Site::query()->get()->keyBy('slug');
        foreach (self::DEPOSITS as $i => [$slug, $amount, $offset]) {
            if ($site = $sites->get($slug)) {
                $this->deposit($site, $amount, $offset, $i);
            }
        }

        $this->specialCases();
        $this->automaticProcessing();
        $this->history();
        $this->notifications();
    }

    private function create(array $data): Payment
    {
        $this->number++;

        return Payment::create([
            'reference' => 'OP-2026-'.sprintf('%05d', $this->number),
            'calc_status' => 'Calculé', 'pay_status' => 'Non initié', 'payout_status' => 'Non disponible', 'reconciliation' => 'Non rapproché',
            'source' => 'Pointages clôturés', 'responsible' => ['N. Yao', 'A. Koné', 'M. Diabaté'][crc32('op-'.$this->number) % 3], 'rule_version' => 'COM-2026-v3',
            'attendance_refs' => [], 'documents' => [], 'reported_anomalies' => [], 'attempts' => 0,
            ...$data,
        ]);
    }

    public static function mask(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) < 6 ? '••••' : '+225 '.substr($d, -10, 2).' '.substr($d, -8, 2).' •• •• '.substr($d, -2);
    }

    /**
     * Moyens de paiement, puis validation, paiement, rapprochement et reversement selon l’ancienneté.
     */
    private function settle(Payment $x, int $h, int $age): void
    {
        $method = self::METHODS[$h % 5];
        $data = [
            'method' => $method, 'provider' => config('admin.payments.providers')[$method], 'account' => self::mask('0707'.(100000 + $h % 899999)),
            'worker_method' => self::WORKER_METHODS[$h % 3], 'worker_account' => self::mask($x->worker?->phone),
        ];
        $end = $x->period_end->toImmutable();
        if ($age < 8 && $x->source === 'Pointages clôturés') {
            $x->update([...$data, 'calc_status' => $h % 3 ? 'Calculé' : 'En attente de validation']);

            return;
        }
        $data += ['calc_status' => 'Validé', 'validated_by' => 'A. Koné', 'validated_at' => $end->addDay()];
        if ($age < 6 && $h % 3 === 0) {
            $x->update([...$data, 'pay_status' => 'En attente']);

            return;
        }
        if ($h % 17 === 0) {
            $x->update([...$data, 'pay_status' => 'Échoué', 'attempts' => 2, 'provider_message' => 'Solde insuffisant (code 51)', 'initiated_at' => $end->addDays(2)]);

            return;
        }
        $data += [
            'pay_status' => 'Réussi', 'attempts' => 1, 'initiated_at' => $end->addDays(2), 'paid_at' => $end->addDays(2),
            'external_reference' => strtoupper(substr($data['provider'], 0, 3)).'-'.(7400000 + $h % 99999), 'provider_message' => 'Transaction approuvée',
            'reconciliation' => $h % 13 === 0 ? 'À vérifier' : ($h % 11 === 0 ? 'Correspondance trouvée' : 'Rapproché automatiquement'),
        ];
        if ($x->isDeposit()) {
            $x->update($data);

            return;
        }
        $payout = $h % 19 === 0 ? 'Échoué' : ($age < 9 && $h % 4 === 0 ? 'À préparer' : 'Réussi');
        $x->update([
            ...$data,
            'payout_status' => $payout,
            'payout_at' => $payout === 'Réussi' ? $end->addDays(3) : null,
            'payout_reference' => $payout === 'Réussi' ? 'REV-'.(600000 + $h % 99999) : null,
            'payout_message' => $payout === 'Échoué' ? 'Numéro bénéficiaire non enregistré' : null,
        ]);
    }

    private function fromAttendances(Collection $list, string $key, Worker $worker): void
    {
        $closed = $list->filter(fn (Attendance $p): bool => $p->isClosed() && ! $p->absence && in_array($p->departure['status'], ['Validé', 'Corrigé'], true));
        $open = $list->reject(fn (Attendance $p): bool => $p->isClosed());
        if ($closed->isEmpty() && $open->reject(fn (Attendance $p): bool => (bool) $p->absence)->isEmpty()) {
            return;
        }
        $end = $list->max('date')->toImmutable();
        $h = crc32($key);
        $x = $this->create([
            'date' => $end, 'type' => $closed->count() + $open->count() > 1 ? 'Paiement hebdomadaire' : 'Paiement journalier',
            'worker_id' => $worker->id, 'site_id' => $list->first()->site_id, 'assignment_reference' => $list->first()->assignment_reference,
            'period_start' => $end->startOfWeek(), 'period_end' => $end, 'days' => $closed->count(), 'open_days' => $open->count(), 'daily_rate' => $worker->daily_rate,
            'attendance_refs' => $list->pluck('reference')->all(),
        ]);
        $x->setRelation('worker', $worker);
        if ($open->isNotEmpty()) {
            $x->update(['calc_status' => 'À vérifier', 'method' => 'Orange Money', 'provider' => 'Orange CI', 'account' => self::mask('0707123456'), 'worker_method' => 'Wave', 'worker_account' => self::mask($worker->phone)]);
        } else {
            $this->settle($x, $h, (int) $end->diffInDays($this->today));
        }
        if ($h % 5 === 0) {
            $x->items()->create(['kind' => 'indemnity', 'type' => 'Transport', 'amount' => 1000 * max(1, $x->days), 'motif' => 'Indemnité de transport forfaitaire', 'author' => 'A. Koné', 'date' => $end, 'status' => 'Validé']);
        }
        if ($h % 7 === 0 && $x->days >= 3) {
            $x->items()->create(['kind' => 'bonus', 'type' => 'Prime de ponctualité', 'amount' => 5000, 'motif' => 'Aucun retard sur la semaine', 'author' => 'A. Koné', 'date' => $end, 'status' => 'Validé']);
        }
    }

    private function archived(Worker $w, int $wi, int $siteId, CarbonImmutable $firstMonday): void
    {
        for ($k = 1; $k <= 10; $k++) {
            $monday = $firstMonday->subWeeks($k);
            $end = $monday->addDays(5);
            $h = crc32($w->slug.$k);
            $days = 4 + $h % 3;
            $x = $this->create([
                'date' => $end, 'type' => 'Paiement hebdomadaire', 'worker_id' => $w->id, 'site_id' => $siteId, 'assignment_reference' => 'AFF-2026-'.sprintf('%04d', 310 + $wi),
                'period_start' => $monday, 'period_end' => $end, 'days' => $days, 'daily_rate' => $w->daily_rate, 'source' => 'Pointages clôturés (archivés)',
            ]);
            $x->setRelation('worker', $w);
            $this->settle($x, $h, (int) $end->diffInDays($this->today));
            if ($h % 6 === 0) {
                $x->items()->create(['kind' => 'bonus', 'type' => 'Prime de chantier', 'amount' => 10000, 'motif' => 'Objectif de coulage atteint', 'author' => 'A. Koné', 'date' => $end, 'status' => 'Validé']);
            }
            if ($h % 4 === 0) {
                $x->items()->create(['kind' => 'indemnity', 'type' => 'Repas', 'amount' => 500 * $days, 'motif' => 'Indemnité repas', 'author' => 'A. Koné', 'date' => $end, 'status' => 'Validé']);
            }
            if ($h % 9 === 0) {
                $x->items()->create(['kind' => 'deduction', 'type' => 'Avance antérieure', 'amount' => 5000, 'base' => 'Avance du '.$monday->subDays(10)->format('d/m/Y'), 'motif' => 'Remboursement d’avance sur salaire', 'proof' => 'avance-signee.pdf', 'author' => 'M. Diabaté', 'approver' => 'A. Koné', 'date' => $end, 'status' => 'Approuvée']);
            }
        }
    }

    private function deposit(Site $site, int $amount, int $offset, int $i): void
    {
        $end = $this->today->addDays($offset);
        $x = $this->create([
            'date' => $end, 'type' => Payment::DEPOSIT, 'site_id' => $site->id, 'period_start' => $end->subDays(7), 'period_end' => $end,
            'fixed_amount' => $amount, 'source' => 'Échéancier du chantier',
        ]);
        $this->settle($x, crc32($site->slug) * 3 + $i, -$offset);
    }

    /**
     * Remboursement partiel effectué, remboursement total demandé, référence externe dupliquée et écart d’encaissement.
     */
    private function specialCases(): void
    {
        $paid = Payment::with(PaymentLedger::RELATIONS)->where('pay_status', 'Réussi')->whereNotNull('worker_id')->orderBy('id')->get()->values();
        if ($r1 = $paid->get(3)) {
            $c = PaymentLedger::calculate($r1);
            $r1->refunds()->create([
                'type' => 'Remboursement partiel', 'amount' => (int) round($c['promoterTotal'] * 0.2 / 100) * 100, 'motif' => 'Journée contestée par le promoteur', 'beneficiary' => $r1->site->promoter_name,
                'responsibility' => 'Ouvrier absent le vendredi', 'rule' => 'Remboursement proportionnel', 'proof' => 'pv-contestation.pdf', 'author' => 'M. Diabaté', 'approver' => 'A. Koné',
                'date' => $r1->period_end->addDays(4), 'status' => 'Effectué', 'timing' => $r1->payout_status === 'Réussi' ? 'Après reversement' : 'Avant reversement',
            ]);
            $r1->update(['pay_status' => 'Partiellement remboursé']);
        }
        if ($r2 = $paid->get(8)) {
            $r2->refunds()->create([
                'type' => 'Remboursement total', 'amount' => PaymentLedger::calculate($r2)['promoterTotal'], 'motif' => 'Prestation annulée · chantier inaccessible', 'beneficiary' => $r2->site->promoter_name,
                'responsibility' => 'O’CHANTIER', 'rule' => 'Remboursement proportionnel', 'author' => 'N. Yao', 'date' => $this->today, 'status' => 'Demandé', 'timing' => 'Après reversement',
            ]);
        }
        $duplicate = $paid->get(14);
        if ($gap = $paid->get(20)) {
            $gap->update([
                'external_reference' => $duplicate?->external_reference ?? $gap->external_reference,
                'received_amount' => PaymentLedger::calculate($gap)['promoterTotal'] - 1500,
                'reconciliation' => 'Écart détecté',
            ]);
        }
    }

    /**
     * Prélèvement automatique : les calculs restants sont validés et les paiements non initiés attendus.
     */
    private function automaticProcessing(): void
    {
        foreach (Payment::query()->get() as $p) {
            $data = [];
            if (in_array($p->calc_status, ['Calculé', 'En attente de validation'], true)) {
                $data += ['calc_status' => 'Validé', 'validated_by' => 'Système (automatique)', 'validated_at' => $p->period_end->addDay()];
            }
            if ($p->pay_status === 'Non initié' && ($data['calc_status'] ?? $p->calc_status) === 'Validé') {
                $data['pay_status'] = 'En attente';
            }
            if ($p->pay_status === 'Échoué') {
                $data['provider_message'] = $p->provider_message.' · nouveau prélèvement automatique programmé';
            }
            if (in_array($p->reconciliation, ['Non rapproché', 'Correspondance trouvée'], true)) {
                $data['reconciliation'] = 'Rapproché automatiquement';
            }
            if ($data) {
                $p->update($data);
            }
        }
    }

    /**
     * Journal (calcul, validation, paiement, reversement) et documents émis.
     */
    private function history(): void
    {
        foreach (Payment::with(PaymentLedger::RELATIONS)->get() as $p) {
            $c = PaymentLedger::calculate($p);
            $log = fn (CarbonImmutable $day, string $time, string $author, string $category, string $action, ?string $new, ?string $comment, string $origin) => $p->activities()->create([
                'occurred_at' => $day->format('Y-m-d')." {$time}", 'author' => $author, 'category' => $category, 'action' => $action, 'new_value' => $new, 'comment' => $comment, 'origin' => $origin,
            ]);
            $log($p->period_end->toImmutable(), '23:59', 'Système', 'creation', 'a généré le calcul', PaymentLedger::money($c['promoterTotal']), "{$p->source} · règle {$p->rule_version}", 'Système');
            if ($p->validated_at) {
                $log($p->validated_at->toImmutable(), '10:12', "{$p->validated_by} · Administrateur finance", 'validation', 'a validé le calcul', 'Validé', null, 'Back-office');
            }
            if ($p->paid_at) {
                $log($p->paid_at->toImmutable(), '14:40', (string) $p->provider, 'paiement', 'a confirmé le paiement', PaymentLedger::money($c['promoterTotal']), $p->external_reference, 'Prestataire (webhook)');
            }
            if ($p->payout_at) {
                $log($p->payout_at->toImmutable(), '09:05', 'Système', 'paiement', 'a effectué le reversement', PaymentLedger::money($c['net']), $p->payout_reference, 'Système');
            }
            $docs = [
                ...($c['paid'] ? [['Reçu du promoteur', 'REC'], ['Justificatif '.($p->method === 'Virement bancaire' ? 'bancaire' : 'Mobile Money'), 'JUS']] : []),
                ...($p->payout_status === 'Réussi' ? [['Reçu de reversement', 'RRV']] : []),
                ...($p->calc_status === 'Validé' ? [['Relevé de commission', 'RCO'], ['Facture de service', 'FAC']] : []),
            ];
            $date = ($p->paid_at ?? $p->validated_at ?? $p->period_end)->format('Y-m-d');
            $p->update(['documents' => array_map(fn (array $d): array => ['reference' => $d[1].'-'.substr($p->reference, 3), 'type' => $d[0], 'date' => $date, 'status' => 'Émis', 'version' => 1], $docs)]);
        }
    }

    private function notifications(): void
    {
        $list = [
            ['Paiement échoué', 'Finance', 'E-mail', '2 paiements promoteur en échec à relancer.'],
            ['Validation requise', 'A. Koné', 'Notification interne', 'Calculs hebdomadaires en attente de validation.'],
            ['Remboursement demandé', 'A. Koné', 'Notification interne', 'Remboursement total demandé · approbation requise.'],
            ['Reversement échoué', 'M. Diabaté', 'Notification push', 'Numéro bénéficiaire non enregistré.'],
            ['Rapprochement requis', 'Finance', 'E-mail', 'Écart détecté sur un encaissement.'],
            ['Commission encaissée', 'Direction', 'E-mail', 'Commissions de la semaine encaissées.'],
        ];
        foreach ($list as $i => [$type, $recipient, $channel, $content]) {
            PaymentNotification::create(['type' => $type, 'recipient' => $recipient, 'channel' => $channel, 'sent_at' => $this->today->subDays($i)->setTime(8 + $i % 2, 10 + $i), 'content' => $content, 'status' => 'Envoyée']);
        }
    }
}
