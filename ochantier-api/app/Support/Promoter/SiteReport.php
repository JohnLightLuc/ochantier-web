<?php

namespace App\Support\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\RatingScope;
use App\Enums\RatingStatus;
use App\Enums\SiteStatus;
use App\Enums\SiteStepStatus;
use App\Enums\WorkDayStatus;
use App\Models\Assignment;
use App\Models\Rating;
use App\Models\Site;
use App\Models\SiteStep;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\Documents\SimplePdf;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * « Rapport de fin de chantier » : description, technicien, pointages, chronologie, bilan financier et évaluation
 * de l’équipe. Disponible à la fin du chantier, réservé aux comptes abonnés ; téléchargeable en PDF.
 */
class SiteReport
{
    public function __construct(
        private SiteManager $sites,
        private SiteRatings $ratings,
        private SiteInvoices $invoices,
        private SiteAttendance $attendance,
        private PromoterPayments $payments,
        private PromoterSubscriptions $subscriptions,
        private MediaStore $media,
    ) {}

    /**
     * Accès au rapport : disponible, réservé aux abonnés, ou en attente de la fin du chantier.
     *
     * @return array{available: bool, ended: bool, subscribed: bool, lock_message: string|null}
     */
    public function access(Site $site): array
    {
        $ended = $site->status === SiteStatus::Termine;
        $subscribed = $this->subscriptions->isSubscribed($site->promoterProfile);

        return [
            'available' => $ended && $subscribed,
            'ended' => $ended,
            'subscribed' => $subscribed,
            'lock_message' => match (true) {
                $ended && $subscribed => null,
                $subscribed => 'Disponible au téléchargement à la fin du chantier.',
                $ended => 'Réservé aux comptes abonnés : pointages, chronologie, bilan financier et évaluations en un PDF.',
                default => 'À la fin du chantier · réservé aux comptes abonnés.',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(Site $site, User $viewer): array
    {
        $access = $this->access($site);
        $base = [
            ...$access,
            'reference' => $site->reference,
            'title' => 'Rapport de fin de chantier',
            'subtitle' => 'Bilan complet · '.$site->reference,
        ];

        if (! $access['available']) {
            return $base;
        }

        $site->loadMissing(['constructionType', 'mainImageMedia', 'responsibleUser', 'commune', 'promoterProfile.user']);
        [$start, $end] = $this->period($site);
        $attendance = $this->attendanceSection($site);
        $steps = $site->steps()->orderBy('sequence')->get();
        $doneSteps = $steps->where('status', SiteStepStatus::Validee)->count();
        $duration = $start && $end ? (int) $start->diffInDays($end) + 1 : null;
        $promoter = $site->promoterProfile->user;

        return [
            ...$base,
            'generated_at' => now()->toIso8601String(),
            'generated_label' => 'Généré le '.$this->date(CarbonImmutable::now()),
            'site' => [
                'id' => $site->id,
                'title' => $site->label,
                'location' => $this->location($site),
                'photo_url' => $site->mainImageMedia ? $this->media->temporaryUrl($site->mainImageMedia) : null,
            ],
            'kpis' => [
                ['label' => 'Durée', 'value' => $duration ? $duration.' jour'.($duration > 1 ? 's' : '') : '—'],
                ['label' => 'Présence', 'value' => $attendance['presence_rate'] !== null ? $attendance['presence_rate'].' %' : '—'],
                ['label' => 'Étapes', 'value' => $doneSteps.'/'.$steps->count()],
            ],
            'description' => [
                'text' => $site->description,
                'details' => [
                    ['label' => 'Type de chantier', 'value' => $site->work_type?->label() ?? '—'],
                    ['label' => 'Bien immobilier', 'value' => $site->constructionType?->label ?? '—'],
                    ['label' => 'Emplacement', 'value' => $this->location($site) ?? '—'],
                    ['label' => 'Offre', 'value' => $this->offerLabel($site)],
                    ['label' => 'Début', 'value' => $start ? $this->date($start) : '—'],
                    ['label' => 'Fin', 'value' => $end ? $this->date($end) : '—'],
                ],
            ],
            'responsible' => $site->responsibleUser ? $this->sites->person($site->responsibleUser, 'Responsable du chantier') : null,
            'attendance' => $attendance,
            'chronology' => $steps->map(fn (SiteStep $step) => [
                'sequence' => $step->sequence,
                'title' => $step->title,
                'status' => ['value' => $step->status->value, 'label' => $step->status->label()],
                'dates' => match ($step->status) {
                    SiteStepStatus::Validee => ($step->started_at ? $this->date($step->started_at).' → ' : '').$this->date($step->completed_at),
                    SiteStepStatus::EnCours => 'En cours depuis le '.$this->date($step->started_at),
                    SiteStepStatus::AFaire => 'Non démarrée',
                },
            ])->values()->all(),
            'finances' => $this->invoices->balance($site),
            'evaluation' => $this->evaluation($site, $viewer),
            'signature' => [
                'promoter' => trim($promoter->first_name.' '.$promoter->last_name),
                'company' => $promoter->ownedOrganizations()->value('legal_name'),
            ],
        ];
    }

    /**
     * Rapport en PDF ; refusé (403) tant qu’il n’est pas disponible.
     */
    public function pdf(Site $site, User $viewer): string
    {
        $report = $this->report($site, $viewer);

        if (! $report['available']) {
            throw new AccessDeniedHttpException($report['lock_message']);
        }

        $pdf = (new SimplePdf)
            ->title('Rapport de fin de chantier')
            ->text('O’CHANTIER · '.$report['generated_label'], 10)
            ->space()
            ->text($report['site']['title'], 14, true)
            ->text('Réf. '.$report['reference'].($report['site']['location'] ? ' · '.$report['site']['location'] : ''));

        foreach ($report['kpis'] as $kpi) {
            $pdf->row($kpi['label'], $kpi['value']);
        }

        $pdf->heading('1 · Description du chantier');

        foreach ($this->wrap($report['description']['text'] ?? '—') as $line) {
            $pdf->text($line);
        }

        foreach ($report['description']['details'] as $detail) {
            $pdf->row($detail['label'], $detail['value']);
        }

        $pdf->heading('2 · Technicien assigné');
        $responsible = $report['responsible'];
        $pdf->text($responsible ? trim($responsible['name'].' · '.$responsible['role'].($responsible['phone'] ? ' · '.$responsible['phone'] : '')) : 'Aucun technicien assigné.');

        $pdf->heading('3 · Historique des pointages')
            ->text($report['attendance']['summary'].' · '.$report['attendance']['presence_label']);

        foreach ($report['attendance']['workers'] as $worker) {
            $pdf->row($worker['name'].' · '.$worker['role'], $worker['days'].' jours · '.$worker['rate']);
        }

        $pdf->heading('4 · Chronologie des activités');

        if ($report['chronology'] === []) {
            $pdf->text('Aucune étape enregistrée.');
        }

        foreach ($report['chronology'] as $step) {
            $pdf->row($step['sequence'].'. '.$step['title'], $step['dates']);
        }

        $pdf->heading('5 · Bilan financier');

        foreach ($report['finances']['lines'] as $line) {
            $pdf->row($line['label'], $this->payments->money($line['amount']));
        }

        $pdf->row('Total décaissé', $report['finances']['total_disbursed_label'], true)
            ->heading('6 · Évaluation de l’équipe')
            ->text($report['evaluation']['label'])
            ->space(20)
            ->text('Document généré par O’CHANTIER', 9)
            ->text('Promoteur : '.$report['signature']['promoter'].($report['signature']['company'] ? ' · '.$report['signature']['company'] : ''), 9);

        return $pdf->render();
    }

    public function filename(Site $site): string
    {
        return 'Rapport-'.$site->reference.'.pdf';
    }

    /**
     * Pointages validés par ouvrier : jours présents sur jours validés et taux de présence.
     *
     * @return array<string, mixed>
     */
    private function attendanceSection(Site $site): array
    {
        $assignments = Assignment::query()
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey())->whereNotIn('status', [MissionStatus::EnAttenteAcompte, MissionStatus::Annulee]))
            ->whereNot('status', AssignmentStatus::Annulee)
            ->with(SiteAttendance::ASSIGNMENT_WITH)
            ->get()
            ->pipe(fn (Collection $assignments) => $this->attendance->ordered($assignments));
        $days = WorkDay::query()
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->whereNotNull('validated_at')
            ->get(['assignment_id', 'work_date', 'status'])
            ->groupBy('assignment_id');

        $present = 0;
        $total = 0;
        $workers = $assignments->map(function (Assignment $assignment) use ($days, &$present, &$total): array {
            $validated = $days->get($assignment->id, collect());
            $count = $validated->count();
            $here = $validated->where('status', '!=', WorkDayStatus::Absent)->count();
            $present += $here;
            $total += $count;

            return [
                'name' => $this->attendance->name($assignment),
                'role' => $assignment->role_label ?? $assignment->need->trade->label,
                'days' => $here.'/'.$count,
                'rate' => $count ? (int) round($here / $count * 100).' %' : '—',
            ];
        })->values();

        $workDays = $days->flatten()->pluck('work_date')->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->unique()->count();
        $rate = $total ? (int) round($present / $total * 100) : null;

        return [
            'summary' => $assignments->pluck('worker_id')->unique()->count().' ouvrier(s) · '.$workDays.' jour'.($workDays > 1 ? 's' : '').' ouvré'.($workDays > 1 ? 's' : ''),
            'presence_rate' => $rate,
            'presence_label' => 'Présence globale '.($rate !== null ? $rate.' %' : '—'),
            'workers' => $workers->all(),
        ];
    }

    /**
     * @return array{label: string, team_score: int|null, team_satisfaction: string|null, workers_average: float|null, workers_count: int, stars: string}
     */
    private function evaluation(Site $site, User $viewer): array
    {
        $ratings = Rating::query()->where('site_id', $site->getKey())->where('author_user_id', $viewer->getKey())->where('status', RatingStatus::Publie)->get();
        $team = $ratings->firstWhere('rating_scope', RatingScope::Equipe);
        $workers = $ratings->where('rating_scope', RatingScope::Individuel);
        $average = $workers->isEmpty() ? null : round($workers->avg('overall_score'), 1);
        $averageLabel = $average !== null ? str_replace('.', ',', (string) $average).'/5' : null;
        $stars = (int) round($team?->overall_score ?? $average ?? 0);

        return [
            'label' => match (true) {
                $team !== null => 'Équipe : '.$team->overall_score.'/5'.($averageLabel ? ' · ouvriers : '.$averageLabel.' en moyenne' : ''),
                $averageLabel !== null => $workers->count().' ouvrier(s) noté(s) · '.$averageLabel,
                default => 'Aucune évaluation pour le moment',
            },
            'team_score' => $team?->overall_score,
            'team_satisfaction' => $team ? $this->ratings->rating($team)['summary'] : null,
            'workers_average' => $average,
            'workers_count' => $workers->count(),
            'stars' => str_repeat('★', $stars).str_repeat('☆', 5 - $stars),
        ];
    }

    /**
     * Début et fin réels du chantier, à défaut ceux des missions puis ceux prévus.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function period(Site $site): array
    {
        $missions = $site->missions()->whereNotIn('status', [MissionStatus::EnAttenteAcompte, MissionStatus::Annulee])->get();
        $start = $missions->map(fn ($mission) => $mission->actual_start_date ?? $mission->planned_start_date)->filter()->min() ?? $site->planned_start_date;
        $end = $missions->map(fn ($mission) => $mission->actual_end_date ?? $mission->planned_end_date)->filter()->max() ?? $site->planned_end_date;

        return [$start ? CarbonImmutable::parse($start) : null, $end ? CarbonImmutable::parse($end) : null];
    }

    private function offerLabel(Site $site): string
    {
        $offer = $site->offers()->latest()->first();

        return match ($offer?->offer_type?->value) {
            null => '—',
            'contractuelle' => 'Contractuelle (forfait)',
            default => 'Journalière',
        };
    }

    private function location(Site $site): ?string
    {
        return collect([$site->address, $site->commune?->name])->filter()->unique()->implode(', ') ?: null;
    }

    /**
     * Découpe un paragraphe en lignes d’environ 95 caractères pour le PDF.
     *
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        return collect(preg_split('/\R/', $text) ?: [])->flatMap(fn (string $line) => explode("\n", wordwrap($line, 95)))->values()->all();
    }

    private function date(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
