<?php

namespace Database\Seeders;

use App\Enums\SiteStatus;
use App\Enums\WorkerStatus;
use App\Models\FinanceArchive;
use App\Models\FinanceAudit;
use App\Models\FinanceClosing;
use App\Models\FinanceReport;
use App\Models\FinanceView;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Worker;
use App\Support\FinanceLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Finance & Reporting de la maquette :
 * - commissions archivées des périodes clôturées (12 chantiers × 2 créneaux par semaine, depuis le premier mois
 *   analysé jusqu’au début de l’historique du module Paiements), avec annulations, échecs et remboursements ;
 * - deux opérations de test, exclues des indicateurs ;
 * - clôtures mensuelles avec instantanés (dont une période rouverte puis clôturée à nouveau), mois précédent à
 *   contrôler et mois en cours ouvert ;
 * - journal d’audit, rapport généré et vues enregistrées.
 */
class FinanceSeeder extends Seeder
{
    private const METHODS = ['Orange Money', 'MTN Mobile Money', 'Wave', 'Moov Money', 'Virement bancaire', 'Carte bancaire'];

    private const WORKER_METHODS = ['Orange Money', 'Wave', 'MTN Mobile Money'];

    private const RESPONSIBLES = ['N. Yao', 'A. Koné', 'M. Diabaté'];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $today = FinanceLedger::today();
        $months = FinanceLedger::months();
        $this->archives($months, CarbonImmutable::parse(Payment::query()->min('date') ?? $today));
        $this->closings($months, $today);
        $this->audit($months, $today);

        FinanceReport::create(['name' => 'Rapport financier de synthèse', 'format' => 'PDF', 'period' => 'Août 2026', 'basis' => 'Date d’opération', 'author' => 'A. Koné', 'rows' => 34, 'generated_at' => $today->subDays(3)->setTime(9, 20)]);
        FinanceView::create(['name' => 'Créances échues · tous promoteurs', 'filters' => ['commission' => 'À encaisser'], 'basis' => 'op', 'period' => 'annee', 'author' => 'A. Koné', 'shared' => ['Direction générale']]);
        FinanceView::create(['name' => 'Gros œuvre · Cocody', 'filters' => ['prestation' => 'Gros œuvre', 'commune' => 'Cocody'], 'basis' => 'presta', 'period' => 'trim', 'author' => 'N. Yao', 'shared' => []]);
    }

    /**
     * @param  array<int, string>  $months
     */
    private function archives(array $months, CarbonImmutable $first): void
    {
        $sites = Site::query()->where('status', '!=', SiteStatus::Draft)->whereNotNull('promoter_name')->orderBy('id')->limit(12)->get()->values();
        $workers = Worker::query()->where('status', WorkerStatus::Validated)->where('daily_rate', '>', 0)->orderBy('id')->get()->values();
        if ($sites->isEmpty() || $workers->isEmpty()) {
            return;
        }
        $providers = config('admin.payments.providers');
        $fees = config('admin.payments.fees');
        $n = 0;
        for ($week = CarbonImmutable::parse("{$months[0]}-01")->startOfWeek(); $week->addDays(5)->lessThan($first); $week = $week->addWeek()) {
            $growth = max(0, (int) array_search($week->format('Y-m'), $months, true));
            foreach ($sites as $ci => $site) {
                foreach ([0, 1] as $slot) {
                    $h = crc32($site->slug.$week->format('Y-m-d').$slot);
                    if ($h % 100 >= 46 + $growth * 4) {
                        continue;
                    }
                    $worker = $workers[($h + $ci * 3 + $slot) % $workers->count()];
                    $n++;
                    $day = $week->addDays(5);
                    $y = $day->year < 2026 ? 7 : 8;
                    $days = 4 + $h % 3;
                    $method = self::METHODS[$h % count(self::METHODS)];
                    $gross = $days * $worker->daily_rate;
                    $fee = $fees[$method] ?? [0, 0];
                    $sub = $gross + (int) round($gross * $y / 100);
                    $total = $sub + (int) round($sub * $fee[0] / 100) + $fee[1];
                    $cancelled = $h % 53 === 0;
                    $failed = ! $cancelled && $h % 71 === 0;
                    $paid = ! $cancelled && ! $failed;
                    $initiated = $day->addDays(2);
                    $paidAt = $paid ? $initiated->addDays($h % 5 === 0 ? 1 : 0) : null;
                    $collected = $paidAt && $method === 'Virement bancaire' ? $paidAt->addDay() : $paidAt;
                    $refund = $paid && $h % 29 === 0 ? ($h % 58 === 0 ? $total : (int) round($total * 0.2 / 100) * 100) : 0;
                    FinanceArchive::create([
                        'reference' => 'COM-A'.$day->format('ym').'-'.sprintf('%04d', $n), 'operation_reference' => 'OP-'.$day->year.'-A'.sprintf('%04d', $n),
                        'external_reference' => $paid ? strtoupper(mb_substr($providers[$method], 0, 3)).'-'.(5100000 + $h % 99999) : null,
                        'site_id' => $site->id, 'worker_id' => $worker->id, 'type' => 'Paiement hebdomadaire', 'service_date' => $day,
                        'initiated_at' => $initiated, 'paid_at' => $paidAt, 'collected_at' => $collected, 'payout_at' => $collected?->addDays($h % 7 === 0 ? 4 : 1), 'reconciled_at' => $collected?->addDay(),
                        'days' => $days, 'daily_rate' => $worker->daily_rate, 'promoter_rate' => $y, 'worker_rate' => 10, 'method' => $method, 'provider' => $providers[$method], 'worker_method' => self::WORKER_METHODS[$h % 3],
                        'pay_status' => match (true) {
                            $cancelled => 'Annulé',
                            $failed => 'Échoué',
                            $refund >= $total => 'Remboursé',
                            $refund > 0 => 'Partiellement remboursé',
                            default => 'Réussi',
                        },
                        'payout_status' => $paid ? 'Réussi' : 'Non disponible',
                        'commission_status' => match (true) {
                            $cancelled => 'Annulée',
                            $failed => 'À encaisser',
                            $refund >= $total => 'Remboursée',
                            $refund > 0 => 'Partiellement remboursée',
                            default => 'Encaissée',
                        },
                        'reconciliation' => $paid ? 'Rapproché automatiquement' : 'Non rapproché', 'attempts' => $failed ? 3 : 1,
                        'message' => $failed ? 'Échecs répétés · dossier transmis au recouvrement' : null,
                        'refunds' => $refund ? [[
                            'type' => $refund >= $total ? 'Remboursement total' : 'Remboursement partiel', 'amount' => $refund, 'motif' => ['Journée contestée par le promoteur', 'Absence non signalée', 'Erreur de facturation'][$h % 3],
                            'beneficiary' => $site->promoter_name, 'status' => 'Effectué', 'date' => $collected->addDays(5)->format('Y-m-d'),
                        ]] : [],
                        'rule_version' => $y === 7 ? 'COM-2025-v2' : 'COM-2026-v3', 'responsible' => self::RESPONSIBLES[$h % 3],
                    ]);
                }
            }
        }
        FinanceArchive::query()->orderBy('id')->limit(2)->get()->each(function (FinanceArchive $a, int $i): void {
            $a->replicate()->fill([
                'reference' => 'COM-TEST-000'.($i + 1), 'operation_reference' => 'OP-TEST-000'.($i + 1), 'external_reference' => 'SANDBOX-'.($i + 1),
                'is_test' => true, 'responsible' => 'Équipe technique', 'refunds' => [],
            ])->save();
        });
    }

    /**
     * Mois antérieurs au mois précédent clôturés (instantané v1), une période rouverte puis clôturée à nouveau (v2),
     * mois précédent à contrôler et mois en cours ouvert.
     *
     * @param  array<int, string>  $months
     */
    private function closings(array $months, CarbonImmutable $today): void
    {
        $ledger = new FinanceLedger;
        $previous = $months[count($months) - 2];
        $reopened = $months[count($months) - 4];
        foreach ($months as $m) {
            if ($m >= $previous) {
                FinanceClosing::create(['month' => $m, 'status' => $m === $previous ? 'À contrôler' : 'Ouverte', 'version' => 0, 'history' => []]);

                continue;
            }
            $closedAt = CarbonImmutable::parse("{$m}-01")->addMonth()->setDay(5)->format('Y-m-d');
            $snapshot = $ledger->snapshot($m, 'A. Koné · Contrôleur de gestion', $closedAt, 1);
            if ($m === $reopened) {
                FinanceClosing::create(['month' => $m, 'status' => 'Clôturée', 'version' => 2, 'history' => [$snapshot], 'reason' => 'Remboursement enregistré après la clôture initiale',
                    'snapshot' => [...$snapshot, 'v' => 2, 'at' => substr($closedAt, 0, 8).'12', 'by' => 'N. Yao · Direction financière']]);

                continue;
            }
            FinanceClosing::create(['month' => $m, 'status' => 'Clôturée', 'version' => 1, 'snapshot' => $snapshot, 'history' => []]);
        }
    }

    /**
     * @param  array<int, string>  $months
     */
    private function audit(array $months, CarbonImmutable $today): void
    {
        $events = [
            ['a clôturé la période', 'Clôture '.FinanceLedger::monthLabel($months[count($months) - 3]), 'À contrôler', 'Clôturée', 'Instantané v1 enregistré', 'statut', 'A. Koné · Contrôleur de gestion', $today->subDays(18)],
            ['a rouvert la période', 'Clôture '.FinanceLedger::monthLabel($months[count($months) - 4]), 'Clôturée', 'Rouverte avec autorisation', 'Remboursement enregistré après la clôture initiale · ancien instantané conservé', 'statut', 'N. Yao · Direction financière', $today->subDays(60)],
            ['a exporté le rapport des commissions', 'Rapports', null, 'Excel', 'Période : août 2026 · base date d’opération', 'document', 'A. Koné · Contrôleur de gestion', $today->subDays(3)],
            ['a consulté les données ouvriers', 'Par ouvrier', null, null, 'Données sensibles · montants nets', 'controle', 'A. Koné · Contrôleur de gestion', $today->subDays(2)],
            ['a modifié la commission ouvrier dans Paramètres', 'Paramètres', '8 %', '10 %', 'Règle COM-2026-v3', 'modification', 'N. Yao · Direction financière', CarbonImmutable::parse('2026-09-18')],
        ];
        foreach ($events as $i => [$action, $scope, $old, $new, $comment, $category, $author, $day]) {
            FinanceAudit::create([
                'occurred_at' => $day->setTime(10, 10 + $i * 7), 'author' => $author, 'category' => $category, 'action' => $action, 'old_value' => $old, 'new_value' => $new,
                'comment' => "{$scope} · {$comment}", 'origin' => 'Back-office · IP 41.207.x.x',
            ]);
        }
    }
}
