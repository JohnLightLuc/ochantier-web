<?php

namespace App\Http\Controllers;

use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerStatus;
use App\Models\Worker;
use App\Models\WorkerPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Affectations, pointages et versements vus depuis la fiche ouvrier.
 *
 * Données de démonstration (`demo_relations`) : ce contrôleur disparaîtra avec les modules
 * Affectations, Pointages et Paiements.
 */
class WorkerMissionController extends Controller
{
    public const MISSION_TYPES = ['Ponctuelle', 'Plusieurs jours', 'Contractuelle'];

    public const PAY_MODES = ['Journalier', 'Hebdomadaire', 'Contractuel'];

    public const ANOMALY_TYPES = ['Retard', 'Départ anticipé', 'Absence', 'Photo non conforme', 'Signature manquante', 'Localisation incohérente'];

    /**
     * Transitions d’affectation : action → [statuts de départ, statut d’arrivée, libellé du journal].
     */
    private const TRANSITIONS = [
        'accepter' => [['Proposée', 'En attente d’acceptation'], 'Acceptée', 'a enregistré l’acceptation'],
        'refuser' => [['Proposée', 'En attente d’acceptation'], 'Refusée', 'a enregistré le refus'],
        'confirmer' => [['Proposée', 'En attente d’acceptation', 'Acceptée', 'À valider'], 'Confirmée', 'a confirmé l’affectation'],
        'suspendre' => [['En cours'], 'Suspendue', 'a suspendu la mission'],
        'retirer' => [['Proposée', 'En attente d’acceptation', 'Acceptée', 'À valider', 'Confirmée', 'En cours', 'Suspendue', 'Remplacement demandé'], 'Annulée', 'a retiré l’affectation'],
    ];

    public function proposeAssignment(Request $request, Worker $worker): RedirectResponse
    {
        abort_unless($worker->status === WorkerStatus::Validated, 422, 'Le profil doit être validé avant toute affectation.');

        $sites = collect(config('admin.demo_sites'));
        $data = $request->validate([
            'site' => ['required', Rule::in($sites->pluck(0)->all())],
            'offer' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(self::MISSION_TYPES)],
            'pay' => ['required', Rule::in(self::PAY_MODES)],
            'start' => ['required', 'date', 'after_or_equal:today'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'amount' => ['required', 'integer', 'min:1'],
        ], [
            '*.required' => 'Ce champ est obligatoire.',
            'start.after_or_equal' => 'La date doit être postérieure à aujourd’hui.',
            'end.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'amount.*' => 'Montant entier en FCFA.',
        ]);

        foreach (['start', 'end'] as $field) {
            if ($conflict = $this->conflict($worker, $data[$field])) {
                throw ValidationException::withMessages([$field => $conflict]);
            }
        }

        $site = $sites->firstWhere(0, $data['site']);
        $assignment = [
            'id' => 'a'.now()->format('YmdHisv'), 'site' => $site[0], 'promoter' => $site[1], 'offer' => $data['offer'], 'trade' => $worker->trade,
            'type' => $data['type'], 'start' => $data['start'], 'end' => $data['end'], 'pay' => $data['pay'], 'amount' => (int) $data['amount'], 'status' => 'Proposée', 'progress' => 0,
        ];
        $worker->putDemo('assignments', [$assignment, ...$worker->demo('assignments')]);
        if ($worker->availability === WorkerAvailability::Available) {
            $worker->update(['availability' => WorkerAvailability::PreAssigned]);
        }
        $worker->logActivity(WorkerActivityCategory::Assignment, 'a proposé une affectation', null, 'Proposée', $site[0].' · '.$this->day($data['start']).' → '.$this->day($data['end']));

        Inertia::flash('toast', 'Affectation proposée · l’ouvrier a été notifié.');

        return back();
    }

    /**
     * Une période bloquante (indisponibilité, repos, absence) couvre-t-elle cette date ?
     */
    private function conflict(Worker $worker, string $date): ?string
    {
        $period = $worker->periods->first(fn (WorkerPeriod $p): bool => $p->type->blocksAssignment() && $date >= $p->starts_on->format('Y-m-d') && $date <= $p->ends_on->format('Y-m-d'));

        return $period ? 'Conflit : '.mb_strtolower($period->type->label()).' déclarée du '.$period->starts_on->translatedFormat('d M Y').' au '.$period->ends_on->translatedFormat('d M Y').'.' : null;
    }

    public function transitionAssignment(Request $request, Worker $worker, string $assignment, string $action): RedirectResponse
    {
        [$from, $to, $label] = self::TRANSITIONS[$action] ?? abort(404);
        $item = $worker->findDemo('assignments', $assignment);
        abort_unless(in_array($item['status'], $from, true), 422, 'Action impossible dans l’état actuel de l’affectation.');

        $comment = $item['site'];
        if ($action === 'retirer') {
            $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], ['reason.*' => 'Ce champ est obligatoire (10 caractères minimum).'])['reason'];
            $comment .= ' · '.trim($reason);
        }

        $worker->updateDemo('assignments', $assignment, ['status' => $to]);
        if ($to === 'Confirmée' && $worker->availability === WorkerAvailability::PreAssigned) {
            $worker->update(['availability' => WorkerAvailability::Assigned]);
        }
        $worker->logActivity(WorkerActivityCategory::Assignment, $label, $item['status'], $to, $comment);

        Inertia::flash('toast', $action === 'retirer' ? 'Affectation retirée.' : 'Affectation '.mb_strtolower($to).'.');

        return back();
    }

    public function flagTimesheet(Request $request, Worker $worker, string $timesheet): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::ANOMALY_TYPES)],
            'comment' => ['required', 'string', 'min:5', 'max:500'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'comment.min' => '5 caractères minimum.']);

        $item = $worker->findDemo('timesheets', $timesheet);
        $worker->updateDemo('timesheets', $timesheet, ['status' => 'À vérifier', 'anomaly' => "{$data['type']} — ".trim($data['comment'])]);
        $worker->logActivity(WorkerActivityCategory::Timesheet, 'a signalé une anomalie', $item['status'], 'À vérifier', $this->day($item['date'])." · {$data['type']}");

        Inertia::flash('toast', 'Anomalie signalée.');

        return back();
    }

    public function correctTimesheet(Request $request, Worker $worker, string $timesheet): RedirectResponse
    {
        $data = $request->validate([
            'in' => ['required', 'date_format:H:i'],
            'out' => ['required', 'date_format:H:i', 'after:in'],
            'justification' => ['required', 'string', 'min:10', 'max:500'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'out.after' => 'Le départ doit suivre l’arrivée.', 'justification.min' => '10 caractères minimum.']);

        $item = $worker->findDemo('timesheets', $timesheet);
        $worker->updateDemo('timesheets', $timesheet, ['in' => $data['in'], 'out' => $data['out'], 'status' => 'Corrigé', 'correction' => trim($data['justification']), 'photoOk' => true]);
        $worker->logActivity(WorkerActivityCategory::Timesheet, 'a corrigé un pointage', ($item['in'] ?: '—').' – '.($item['out'] ?: '—'), "{$data['in']} – {$data['out']}", trim($data['justification']));

        Inertia::flash('toast', 'Pointage corrigé.');

        return back();
    }

    public function approveTimesheet(Worker $worker, string $timesheet): RedirectResponse
    {
        $item = $worker->findDemo('timesheets', $timesheet);
        abort_if($item['status'] === 'Validé', 422);

        $worker->updateDemo('timesheets', $timesheet, ['status' => 'Validé']);
        $worker->logActivity(WorkerActivityCategory::Timesheet, 'a validé un pointage', $item['status'], 'Validé', $this->day($item['date']));

        Inertia::flash('toast', 'Pointage validé.');

        return back();
    }

    public function exportTimesheets(Worker $worker): StreamedResponse
    {
        return response()->streamDownload(function () use ($worker): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Date', 'Chantier', 'Arrivée', 'Départ', 'Durée', 'Statut', 'Anomalie', 'Validation promoteur'], ';');
            foreach ($worker->demo('timesheets') as $t) {
                fputcsv($out, [$t['date'], $t['site'], $t['in'], $t['out'], $this->duration($t['in'], $t['out']), $t['status'], $t['anomaly'], $t['validation']], ';');
            }
            fclose($out);
        }, "pointages-{$worker->slug}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Durée travaillée, pause déjeuner d’une heure déduite.
     */
    private function duration(string $in, string $out): string
    {
        if (! $in || ! $out) {
            return '—';
        }
        $minutes = Carbon::parse($in)->diffInMinutes(Carbon::parse($out)) - 60;

        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    public function validatePayout(Worker $worker, string $payout): RedirectResponse
    {
        abort_unless($worker->findDemo('payouts', $payout)['status'] === 'En attente de validation', 422);

        $item = $worker->updateDemo('payouts', $payout, ['status' => 'Validé']);
        $worker->logActivity(WorkerActivityCategory::Payment, 'a validé un paiement', 'En attente de validation', 'Validé', $item['ref']);
        Inertia::flash('toast', 'Paiement validé.');

        return back();
    }

    public function retryPayout(Worker $worker, string $payout): RedirectResponse
    {
        abort_unless($worker->findDemo('payouts', $payout)['status'] === 'Échoué', 422);

        $item = $worker->updateDemo('payouts', $payout, ['status' => 'En cours']);
        $worker->logActivity(WorkerActivityCategory::Payment, 'a relancé un versement', 'Échoué', 'En cours', $item['ref']);
        Inertia::flash('toast', 'Versement relancé.');

        return back();
    }

    /**
     * Relevé texte d’un versement : brut, commission, net.
     */
    public function payoutStatement(Worker $worker, string $payout): HttpResponse
    {
        $p = $worker->findDemo('payouts', $payout);
        $rate = (int) config('admin.worker_commission_rate');
        $gross = $p['days'] * $worker->daily_rate;
        $commission = (int) round($gross * $rate / 100);
        $money = fn (int $v): string => number_format($v, 0, ',', ' ').' FCFA';

        $content = implode("\n", [
            "Relevé {$p['ref']}", "{$worker->name} ({$worker->code})", $p['site'],
            'Période : '.$this->day($p['from']).' → '.$this->day($p['to']), "Jours validés : {$p['days']}",
            'Brut : '.$money($gross), "Commission {$rate} % : ".$money($commission), 'Net : '.$money($gross - $commission), "Statut : {$p['status']}",
        ])."\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"releve-{$p['ref']}.txt\""]);
    }

    private function day(string $iso): string
    {
        return Carbon::parse($iso)->translatedFormat('d M Y');
    }
}
