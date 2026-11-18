<?php

namespace App\Support\Contractor;

use App\Enums\SiteStatus;
use App\Enums\SiteStepStatus;
use App\Enums\StaffingRequestStatus;
use App\Enums\WorkDayStatus;
use App\Models\Contractor;
use App\Models\PaymentTransaction;
use App\Models\Site;
use App\Models\SiteStep;
use App\Models\StaffingRequest;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\Documents\SimplePdf;
use App\Support\Media\MediaStore;
use App\Support\Promoter\SiteAttendance;
use App\Support\Promoter\SiteRatings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * « Rapport de fin de chantier » de l’entrepreneur : indicateurs (durée, présence, étapes), description, maître
 * d’ouvrage, pointages de son équipe, chronologie, bilan financier vu de l’entrepreneur (contrat, sommes perçues,
 * main-d’œuvre pointée, frais des recherches d’effectifs) et ses évaluations. Disponible à la fin du chantier ;
 * téléchargeable en PDF.
 */
class ContractorReport
{
    public function __construct(
        private ContractorSites $sites,
        private SiteAttendance $attendance,
        private SiteRatings $ratings,
        private MediaStore $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Contractor $contractor, Site $site, User $viewer): array
    {
        $ended = $site->status === SiteStatus::Termine;
        $base = [
            'available' => $ended,
            'lock_message' => $ended ? null : 'Le rapport de fin de chantier (pointages, étapes, bilan financier, évaluation) sera disponible une fois le chantier terminé.',
            'reference' => $site->reference,
            'title' => 'Rapport de fin de chantier',
            'subtitle' => 'Bilan complet · '.$site->reference,
        ];

        if (! $ended) {
            return $base;
        }

        $card = $this->sites->card($contractor, $site);
        $payments = $this->sites->payments($contractor, $site);
        $days = WorkDay::query()->with(['assignment.worker', 'assignment.need.trade'])
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey())->where('contractor_id', $contractor->getKey()))
            ->get();
        $present = $days->where('status', '!=', WorkDayStatus::Absent)->count();
        $presence = $days->isEmpty() ? null : (int) round($present / $days->count() * 100);
        $steps = $site->steps()->orderBy('sequence')->get();
        $doneSteps = $steps->where('status', SiteStepStatus::Validee)->count();
        $promoter = $site->responsibleUser ?? $site->promoterProfile?->user;
        $labour = (int) $days->where('status', WorkDayStatus::Valide)->sum(fn (WorkDay $day) => (int) $this->attendance->dailyRate($day->assignment));
        $staffingFees = $this->staffingPaid($contractor, $site);
        $evaluation = $this->ratings->summary($site, $viewer);

        return [
            ...$base,
            'generated_at' => now()->toIso8601String(),
            'generated_label' => 'Généré le '.CarbonImmutable::now()->format('d/m/Y'),
            'site' => [
                'id' => $site->id,
                'title' => $site->label,
                'location' => $card['place'],
                'photo_url' => $card['image_url'],
            ],
            'kpis' => [
                ['label' => 'Durée', 'value' => $card['duration_days'].' jour'.($card['duration_days'] > 1 ? 's' : '')],
                ['label' => 'Présence', 'value' => $presence !== null ? $presence.' %' : '—'],
                ['label' => 'Étapes', 'value' => $doneSteps.'/'.$steps->count()],
            ],
            'description' => [
                'text' => $site->description,
                'details' => [
                    ['label' => 'Emplacement', 'value' => $site->address ?: $card['place']],
                    ['label' => 'Début', 'value' => CarbonImmutable::parse($card['start_date'])->format('d/m/Y')],
                    ['label' => 'Fin', 'value' => CarbonImmutable::parse($card['end_date'])->format('d/m/Y')],
                    ['label' => 'Maître d’ouvrage', 'value' => $promoter ? (trim($promoter->first_name.' '.$promoter->last_name) ?: '—') : '—'],
                ],
            ],
            'attendance' => [
                'presence_rate' => $presence,
                'summary' => $days->groupBy(fn (WorkDay $day) => $day->work_date->toDateString())->count().' jour(s) pointé(s) · '.$present.' présence(s) · '.$days->where('status', WorkDayStatus::Absent)->count().' absence(s)',
                'workers' => $days->groupBy('assignment.worker_id')->map(fn (Collection $items) => [
                    'name' => $this->attendance->name($items->first()->assignment),
                    'role' => $items->first()->assignment->role_label ?? $items->first()->assignment->need->trade->label,
                    'days' => $items->count(),
                    'presence_rate' => (int) round($items->where('status', '!=', WorkDayStatus::Absent)->count() / $items->count() * 100),
                ])->values()->all(),
            ],
            'chronology' => $steps->map(fn (SiteStep $step) => [
                'sequence' => $step->sequence,
                'title' => $step->title,
                'status' => ['value' => $step->status->value, 'label' => $step->status->label()],
                'dates' => match ($step->status) {
                    SiteStepStatus::Validee => ($step->started_at ? $step->started_at->format('d/m/Y').' → ' : '').$step->completed_at?->format('d/m/Y'),
                    SiteStepStatus::EnCours => 'En cours depuis le '.$step->started_at?->format('d/m/Y'),
                    SiteStepStatus::AFaire => 'Non démarrée',
                },
            ])->values()->all(),
            'finances' => [
                'lines' => [
                    ['label' => 'Montant du contrat (net)', 'amount' => $payments['contract_amount']],
                    ['label' => 'Perçu via O’CHANTIER', 'amount' => $payments['paid_amount']],
                    ['label' => 'Reste à percevoir', 'amount' => $payments['remaining_amount']],
                    ['label' => 'Main-d’œuvre pointée et validée', 'amount' => $labour],
                    ['label' => 'Payé pour les recherches d’effectifs', 'amount' => $staffingFees],
                ],
                'note' => 'Montants nets, hors commission O’CHANTIER. La main-d’œuvre est calculée au tarif journalier convenu.',
            ],
            'evaluation' => [
                'rated_count' => $evaluation['rated_count'],
                'total' => $evaluation['total'],
                'team' => $evaluation['team']['rating'] ?? null,
                'label' => $evaluation['rated_count'].'/'.$evaluation['total'].' ouvriers notés'.(isset($evaluation['team']['rating']) ? ' · équipe : '.$evaluation['team']['rating']['score'].'/5' : ''),
            ],
            'signature' => ['contractor' => $contractor->name],
        ];
    }

    /**
     * Rapport en PDF ; refusé (403) tant que le chantier n’est pas terminé.
     */
    public function pdf(Contractor $contractor, Site $site, User $viewer): string
    {
        $report = $this->report($contractor, $site, $viewer);

        if (! $report['available']) {
            throw new AccessDeniedHttpException($report['lock_message']);
        }

        $pdf = (new SimplePdf)
            ->title('Rapport de fin de chantier')
            ->text('O’CHANTIER · '.$report['generated_label'], 10)
            ->space()
            ->text($report['site']['title'], 14, true)
            ->text('Réf. '.$report['reference'].' · '.$report['site']['location']);

        foreach ($report['kpis'] as $kpi) {
            $pdf->row($kpi['label'], $kpi['value']);
        }

        $pdf->heading('1 · Description du chantier')->text((string) ($report['description']['text'] ?: '—'));

        foreach ($report['description']['details'] as $detail) {
            $pdf->row($detail['label'], $detail['value']);
        }

        $pdf->heading('2 · Pointages de l’équipe')->text($report['attendance']['summary']);

        foreach ($report['attendance']['workers'] as $worker) {
            $pdf->row($worker['name'].' · '.$worker['role'], $worker['days'].' jours · '.$worker['presence_rate'].' %');
        }

        $pdf->heading('3 · Chronologie des activités');

        if ($report['chronology'] === []) {
            $pdf->text('Aucune étape enregistrée.');
        }

        foreach ($report['chronology'] as $step) {
            $pdf->row($step['sequence'].'. '.$step['title'], $step['dates']);
        }

        $pdf->heading('4 · Bilan financier');

        foreach ($report['finances']['lines'] as $line) {
            $pdf->row($line['label'], number_format($line['amount'], 0, ',', "\u{00A0}")."\u{00A0}FCFA");
        }

        return $pdf->text($report['finances']['note'], 9)
            ->heading('5 · Évaluation de l’équipe')
            ->text($report['evaluation']['label'])
            ->space(20)
            ->text('Établi pour '.$report['signature']['contractor'].'.')
            ->render();
    }

    public function filename(Site $site): string
    {
        return 'rapport-'.$site->reference.'.pdf';
    }

    /**
     * Acomptes, frais et échéances payés par l’entrepreneur pour ses recherches d’effectifs sur le chantier.
     */
    private function staffingPaid(Contractor $contractor, Site $site): int
    {
        $requests = StaffingRequest::query()->where('contractor_id', $contractor->getKey())->where('site_id', $site->getKey())->where('status', StaffingRequestStatus::Payee->value)->pluck('id');

        return (int) PaymentTransaction::query()
            ->where('payer_type', $contractor->getMorphClass())
            ->where('payer_id', $contractor->getKey())
            ->where('status', 'reussie')
            ->get()
            ->filter(fn (PaymentTransaction $transaction) => $requests->contains($transaction->metadata['staffing_request_id'] ?? null))
            ->sum('amount');
    }
}
