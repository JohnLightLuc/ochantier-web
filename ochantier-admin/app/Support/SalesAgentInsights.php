<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\SalesAgentStatus;
use App\Enums\WorkerStatus;
use App\Models\SalesAgent;
use App\Models\SalesAgentAdjustment;
use App\Models\SalesAgentDocument;
use App\Models\SalesAgentFieldActivity;
use App\Models\SalesAgentObjective;
use App\Models\SalesAgentPeriod;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Données calculées de la fiche d’un commercial : indicateurs, alertes, historique de classement, gains.
 */
class SalesAgentInsights
{
    /**
     * Signalements de démonstration, en attendant les contrôles automatiques de doublons et d’incohérences.
     */
    private const DEMO_ALERTS = [
        'ousmane-diabate' => ['warning', 'content_copy', 'Doublon potentiel', 'Le numéro secondaire est aussi déclaré par un ouvrier (Moussa Ouattara).'],
        'jean-marc-yao' => ['warning', 'rule', 'Information incohérente', 'L’adresse déclarée diffère de celle du justificatif de domicile.'],
    ];

    public const PAYOUT_TONES = ['En calcul' => 'n', 'En attente de validation' => 'a', 'Validé' => 'b', 'Programmé' => 'b', 'Payé' => 'g', 'Corrigé' => 'p', 'Suspendu' => 'r', 'Annulé' => 'n'];

    /** @var Collection<int, Worker>|null */
    private ?Collection $workers = null;

    public function __construct(private SalesAgent $agent, private SalesPerformance $performance) {}

    /**
     * Ligne de classement résumée pour un mois, ou null si le commercial n’y figure pas.
     *
     * @return array<string, mixed>|null
     */
    public function summary(string $month): ?array
    {
        $row = $this->performance->row($this->agent, $month);
        if (! $row) {
            return null;
        }
        $label = SalesPerformance::performanceLabel($row['score']);

        return [
            'month' => $month,
            'label' => SalesPerformance::monthLabel($month),
            'rank' => $row['rank'],
            'total' => $this->performance->ranking($month)->count(),
            'score' => $row['score'],
            'performance' => ['label' => $label, 'tone' => SalesPerformance::performanceTone($label)],
            'metrics' => $row['metrics'],
            'normalized' => $row['normalized'],
            'gain' => $this->performance->gain($this->agent, $month, $row),
            'closed' => $this->performance->isClosed($month),
            'isTitle' => in_array($month, $this->titles(), true),
        ];
    }

    /**
     * Six derniers mois, du plus ancien au plus récent.
     *
     * @return array<int, array{month: string, label: string, short: string, summary: array<string, mixed>|null}>
     */
    public function history(): array
    {
        return collect(array_slice(SalesPerformance::months(), 0, 6))->reverse()->values()->map(fn (string $m): array => [
            'month' => $m,
            'label' => SalesPerformance::monthLabel($m),
            'short' => Carbon::createFromFormat('Y-m', $m)->startOfMonth()->translatedFormat('M'),
            'summary' => $this->summary($m),
        ])->all();
    }

    /**
     * Mois clôturés où le commercial a été désigné Commercial du mois.
     *
     * @return array<int, string>
     */
    public function titles(): array
    {
        return array_values(array_filter(
            SalesPerformance::months(),
            fn (string $m): bool => $this->performance->isClosed($m) && (bool) ($this->performance->topEligible($m)['agent'] ?? null)?->is($this->agent),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function kpis(): array
    {
        $current = $this->summary(SalesPerformance::currentMonth());
        $m = $current['metrics'] ?? null;

        return [
            ['label' => 'Ouvriers enregistrés', 'value' => (string) ($this->agent->recruited_total ?: $this->workers()->count()), 'icon' => 'engineering', 'tone' => 'g', 'hint' => $m ? "{$m['registered']} ce mois" : 'Aucun ce mois'],
            ['label' => 'Profils validés (mois)', 'value' => (string) ($m['validated'] ?? 0), 'icon' => 'verified', 'tone' => 'b', 'hint' => $m ? "Taux de validation {$m['validationRate']} %" : '—'],
            ['label' => 'Score global', 'value' => $current ? $this->decimal($current['score']).' / 100' : '—', 'icon' => 'insights', 'tone' => 'a', 'hint' => $current ? "Rang {$current['rank']} sur {$current['total']}" : 'Non classé'],
            ['label' => 'Gain du mois', 'value' => number_format($current['gain'] ?? 0, 0, ',', ' '), 'unit' => 'FCFA', 'icon' => 'payments', 'tone' => 'a', 'hint' => 'Estimation en cours'],
        ];
    }

    /**
     * Bandeau d’état du compte (hors compte actif).
     *
     * @return array{tone: string, icon: string, title: string, text: string, action: string|null}|null
     */
    public function banner(): ?array
    {
        $a = $this->agent;
        $date = fn (?string $iso): string => $iso ? Carbon::parse($iso)->translatedFormat('d M Y') : '—';

        $banner = match ($a->status) {
            SalesAgentStatus::Draft => ['neutral', 'edit_note', 'Brouillon.', 'L’invitation n’a pas encore été envoyée.', 'invite'],
            SalesAgentStatus::Invited => ['info', 'forward_to_inbox', 'Invitation envoyée.', 'Envoyée le '.$date($a->access['sent_at'])." à {$a->email} · expire le ".$date($a->access['expires_at']).'.', 'email'],
            SalesAgentStatus::PendingActivation => ['warning', 'hourglass_top', 'Activation en attente.', 'Le lien a été consulté le '.$date($a->access['viewed_at']).' ; le mot de passe n’a pas encore été créé.', 'activate'],
            SalesAgentStatus::Suspended => ['danger', 'block', 'Compte suspendu.', 'Motif : '.($a->suspension_reason ?: '—').'. Non éligible au titre de Commercial du mois.', null],
            SalesAgentStatus::Deactivated => ['neutral', 'person_off', 'Compte désactivé.', 'Historique et gains consultables en lecture seule.', null],
            SalesAgentStatus::Archived => ['neutral', 'archive', 'Commercial archivé.', 'Lecture seule.', null],
            default => null,
        };

        return $banner ? array_combine(['tone', 'icon', 'title', 'text', 'action'], $banner) : null;
    }

    /**
     * Compteurs affichés à côté des onglets.
     *
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        return [
            'profil-documents' => $this->agent->documents->where('status', '!=', DocumentStatus::Verified)->count(),
            'ouvriers' => $this->workers()->count(),
            'activite' => $this->agent->fieldActivities->where('status', '!=', 'Validée')->count(),
        ];
    }

    /**
     * Alertes de l’onglet. `action` : lien vers un onglet ou dépôt d’une pièce.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alerts(string $tab): array
    {
        $base = "/commerciaux/{$this->agent->slug}";

        return match ($tab) {
            '' => [
                ...$this->documentAlerts(),
                ...($this->agent->fieldActivities->contains('status', 'Anomalie signalée') ? [$this->alert('danger', 'report', 'Anomalie terrain', 'Une activité terrain est signalée.', 'Voir', ['type' => 'link', 'href' => "{$base}/activite"])] : []),
                ...($this->agent->status->isPreActivation() ? [$this->alert('info', 'forward_to_inbox', 'Compte non activé', "Invitation : {$this->agent->invitation_status->label()}.", 'Accès et sécurité', ['type' => 'link', 'href' => "{$base}/acces-securite"])] : []),
            ],
            'profil-documents' => $this->documentAlerts(),
            'zones-disponibilites' => $this->conflictAlerts(),
            default => [],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documentAlerts(): array
    {
        $alerts = [];

        foreach ($this->agent->documents as $d) {
            /** @var SalesAgentDocument $d */
            $label = $d->type->label();
            $replace = ['type' => 'document', 'document' => $d->type->value];
            match ($d->status) {
                DocumentStatus::Missing => $alerts[] = $this->alert('warning', 'upload_file', 'Document manquant', $label, 'Ajouter', $replace),
                DocumentStatus::Expired => $alerts[] = $this->alert('orange', 'event_busy', 'Document expiré', "{$label} depuis le ".$d->expires_at?->translatedFormat('d M Y'), 'Remplacer', $replace),
                DocumentStatus::Rejected => $alerts[] = $this->alert('danger', 'visibility_off', 'Document rejeté', "{$label} — {$d->rejection_reason}", 'Remplacer', $replace),
                default => null,
            };
        }
        if ($demo = self::DEMO_ALERTS[$this->agent->slug] ?? null) {
            $alerts[] = $this->alert(...$demo);
        }

        return $alerts;
    }

    /**
     * Activités terrain enregistrées pendant une indisponibilité déclarée.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conflictAlerts(): array
    {
        $alerts = [];

        foreach ($this->agent->periods as $p) {
            /** @var SalesAgentPeriod $p */
            $from = $p->starts_on->format('Y-m-d');
            $to = $p->ends_on->format('Y-m-d');
            $conflict = $p->type->value !== 'partial' && $this->agent->fieldActivities->contains(fn (SalesAgentFieldActivity $a): bool => $a->occurred_at->format('Y-m-d') >= $from && $a->occurred_at->format('Y-m-d') <= $to);
            if ($conflict) {
                $alerts[] = $this->alert('danger', 'event_busy', 'Conflit d’affectation', 'Activités terrain enregistrées pendant « '.mb_strtolower($p->type->label()).' » ('.$p->starts_on->translatedFormat('d M Y').' → '.$p->ends_on->translatedFormat('d M Y').').');
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string, string>|null  $action
     * @return array<string, mixed>
     */
    private function alert(string $tone, string $icon, string $title, string $text, ?string $cta = null, ?array $action = null): array
    {
        return compact('tone', 'icon', 'title', 'text', 'cta', 'action');
    }

    /**
     * Ouvriers enregistrés par le commercial.
     *
     * @return Collection<int, Worker>
     */
    private function workers(): Collection
    {
        return $this->workers ??= $this->agent->recruitedWorkers()->with('documents')->orderByDesc('registered_at')->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workerRows(): array
    {
        return $this->workers()->map(function (Worker $w): array {
            $validation = match (true) {
                $w->status === WorkerStatus::Validated => ['Validé', 'g'],
                $w->status === WorkerStatus::Rejected => ['Rejeté', 'r'],
                $w->status->isUnderReview() => ['À vérifier', 'a'],
                default => ['Non soumis', 'n'],
            };

            return [
                'slug' => $w->slug,
                'name' => $w->name,
                'code' => $w->code,
                'trade' => $w->trade,
                'phone' => $w->phone,
                'commune' => $w->commune,
                'experience' => $w->experience_years,
                'dailyRate' => $w->daily_rate,
                'registeredAt' => $w->registered_at?->format('Y-m-d'),
                'quality' => (int) round($w->documents->where('status', DocumentStatus::Verified)->count() / 6 * 100),
                'complete' => ! $w->documents->contains('status', DocumentStatus::Missing),
                'status' => ['value' => $w->status->value, 'label' => $w->status->label(), 'tone' => $w->status->tone()],
                'validation' => ['label' => $validation[0], 'tone' => $validation[1]],
                'availability' => ['label' => $w->availability->label(), 'tone' => $w->availability->tone()],
                'canSubmit' => in_array($w->status, [WorkerStatus::Incomplete, WorkerStatus::Rejected], true),
            ];
        })->all();
    }

    /**
     * Répartition des ouvriers suivis par métier (6 premiers).
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public function tradeBreakdown(): array
    {
        $counts = $this->workers()->countBy('trade')->all();
        foreach ([...$this->agent->specialties, ...$this->agent->priority_trades] as $i => $trade) {
            $counts[$trade] = ($counts[$trade] ?? 0) + (int) round(($this->agent->recruited_total ?: 12) / (8 + $i * 3));
        }
        arsort($counts);

        return collect($counts)->take(6)->map(fn (int $n, string $t): array => [$t, $n])->values()->all();
    }

    /**
     * Objectifs avec leur réalisation et leur statut.
     *
     * @return array<int, array<string, mixed>>
     */
    public function objectives(): array
    {
        $today = SalesPerformance::referenceDate()->format('Y-m-d');
        $current = $this->summary(SalesPerformance::currentMonth());
        $quarter = collect(array_slice(SalesPerformance::months(), 0, 3))->sum(fn (string $m): int => $this->summary($m)['metrics']['validated'] ?? 0);

        return $this->agent->objectives->map(function (SalesAgentObjective $o) use ($today, $current, $quarter): array {
            $done = match ($o->type) {
                'Hebdomadaire' => (int) round(($current['metrics']['validated'] ?? 0) / 4.3),
                'Trimestriel' => $quarter,
                default => $this->summary($o->starts_on->format('Y-m'))['metrics']['validated'] ?? 0,
            };
            $pct = min(100, (int) round($done / max(1, $o->target) * 100));
            $future = $o->starts_on->format('Y-m-d') > $today;
            $status = $o->closed ? ($pct >= 100 ? 'Atteint' : 'Non atteint') : ($future ? 'À venir' : 'En cours');

            return [
                'id' => $o->id,
                'label' => $o->label,
                'type' => $o->type,
                'target' => $o->target,
                'startsOn' => $o->starts_on->format('Y-m-d'),
                'endsOn' => $o->ends_on->format('Y-m-d'),
                'closed' => $o->closed,
                'future' => $future,
                'done' => $future ? null : $done,
                'progress' => $future ? 0 : $pct,
                'status' => ['label' => $status, 'tone' => ['Atteint' => 'g', 'Non atteint' => 'r', 'À venir' => 'n', 'En cours' => 'b'][$status]],
            ];
        })->all();
    }

    /**
     * Lignes de gains : bonus et retenues saisis, puis gains mensuels calculés.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payouts(): array
    {
        $adjustments = $this->agent->adjustments->map(fn (SalesAgentAdjustment $b): array => [
            'ref' => ($b->isDeduction() ? 'RT-' : 'BN-').str_pad((string) $b->id, 5, '0', STR_PAD_LEFT),
            'adjustmentId' => $b->id,
            'month' => $b->granted_on->format('Y-m'),
            'period' => $b->granted_on->translatedFormat('d M Y'),
            'type' => $b->type,
            'profiles' => 0,
            'fixed' => 0,
            'commissions' => 0,
            'bonus' => max(0, $b->amount),
            'deduction' => max(0, -$b->amount),
            'net' => $b->amount,
            'status' => $b->status,
            'paidAt' => null,
            'method' => $this->agent->pay['method'],
            'reason' => $b->reason,
        ]);
        $monthly = collect($this->performance->payoutLines($this->agent))->map(fn (array $l): array => [...$l, 'adjustmentId' => null, 'reason' => null]);

        return $adjustments->concat($monthly)->map(function (array $l): array {
            $label = $l['status'];

            return [...$l, 'status' => ['label' => $label, 'tone' => self::PAYOUT_TONES[$label] ?? 'n']];
        })->values()->all();
    }

    /**
     * Activités terrain avec l’ouvrier concerné.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldActivities(): array
    {
        return $this->agent->fieldActivities->map(fn (SalesAgentFieldActivity $a): array => [
            'id' => $a->id,
            'date' => $a->occurred_at->format('Y-m-d'),
            'time' => $a->occurred_at->format('H:i'),
            'type' => $a->type,
            'worker' => $a->worker ? ['name' => $a->worker->name, 'slug' => $a->worker->slug] : null,
            'location' => $a->location,
            'comment' => $a->comment,
            'hasProof' => $a->has_proof,
            'status' => ['label' => $a->status, 'tone' => ['Validée' => 'g', 'À vérifier' => 'a', 'Anomalie signalée' => 'r'][$a->status] ?? 'n'],
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function periods(): array
    {
        return $this->agent->periods->map(fn (SalesAgentPeriod $p): array => [
            'id' => $p->id,
            'startsOn' => $p->starts_on->format('Y-m-d'),
            'endsOn' => $p->ends_on->format('Y-m-d'),
            'type' => ['value' => $p->type->value, 'label' => $p->type->label(), 'tone' => $p->type->tone()],
            'reason' => $p->reason,
            'replacement' => $p->replacement,
            'comment' => $p->comment,
        ])->all();
    }

    private function decimal(float $value): string
    {
        return str_replace('.', ',', (string) $value);
    }
}
