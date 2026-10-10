<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerStatus;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Models\WorkerPeriod;
use Illuminate\Support\Carbon;

/**
 * Indicateurs, alertes et suggestions affichés sur la fiche d’un ouvrier.
 */
class WorkerInsights
{
    private readonly int $commissionRate;

    public function __construct(private readonly Worker $worker)
    {
        $this->commissionRate = (int) config('admin.worker_commission_rate');
    }

    /**
     * Montant net journalier, après commission O’CHANTIER.
     */
    public function netDailyRate(): int
    {
        return (int) round($this->worker->daily_rate * (100 - $this->commissionRate) / 100);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function kpis(): array
    {
        $w = $this->worker;
        $stats = $w->demo('stats');
        $net = $this->netDailyRate();
        $paidNet = collect($w->demo('payouts'))->where('status', 'Payé')->sum(fn (array $p): int => $p['days'] * $net);
        $settled = in_array($w->status, [WorkerStatus::Validated, WorkerStatus::Suspended, WorkerStatus::Archived], true);
        $gains = $settled ? (int) round((($stats['days'] ?? 0) * $net + $paidNet) / 1000) * 1000 : 0;
        $rating = $w->rating ? number_format($w->rating, 1, ',', '') : '—';

        return [
            ['label' => 'Missions réalisées', 'value' => (string) ($stats['missions'] ?? 0), 'icon' => 'construction', 'tone' => 'g', 'hint' => collect($w->demo('assignments'))->where('status', 'En cours')->count().' en cours'],
            ['label' => 'Jours travaillés', 'value' => (string) ($stats['days'] ?? 0), 'icon' => 'calendar_month', 'tone' => 'b', 'hint' => 'Taux de présence '.($stats['presence'] ?? 0).' %'],
            ['label' => 'Montant total gagné', 'value' => number_format($gains, 0, ',', ' '), 'unit' => 'FCFA', 'icon' => 'payments', 'tone' => 'a', 'hint' => "Net après commission de {$this->commissionRate} %"],
            ['label' => 'Note moyenne', 'value' => $rating.($w->rating ? ' / 5' : ''), 'icon' => 'star', 'tone' => 'a', 'hint' => "{$w->reviews_count} avis"],
        ];
    }

    /**
     * Compteurs affichés à côté des onglets.
     *
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        $w = $this->worker;

        return [
            'identite-documents' => $w->documents->where('status', '!=', DocumentStatus::Verified)->count(),
            'affectations' => count($w->demo('assignments')),
            'pointages' => collect($w->demo('timesheets'))->whereIn('status', ['À vérifier', 'Contesté', 'Incomplet'])->count(),
            'avis' => count($w->demo('reviews')),
            'remplacements' => collect($w->demo('replacements'))->whereNotIn('status', ['Clôturé', 'Annulé'])->count(),
        ];
    }

    /**
     * Bandeau d’état du profil (suspendu, rejeté, archivé, en vérification…).
     *
     * @return array<string, mixed>|null
     */
    public function banner(): ?array
    {
        $w = $this->worker;
        $count = fn (DocumentStatus $s): int => $w->documents->where('status', $s)->count();
        $rejection = $w->rejection;

        $banner = match ($w->status) {
            WorkerStatus::Suspended => ['danger', 'block', 'Profil suspendu.', 'Motif : '.($w->suspension_reason ?: 'non précisé')],
            WorkerStatus::Rejected => ['danger', 'person_cancel', 'Profil rejeté.', $rejection ? "{$rejection['reason']} — {$rejection['comment']} Action demandée : {$rejection['request']}." : ''],
            WorkerStatus::Archived => ['neutral', 'archive', 'Profil archivé.', 'Lecture seule — réactivez le profil pour le modifier.'],
            WorkerStatus::Incomplete => ['warning', 'rule', 'Profil incomplet.', $count(DocumentStatus::Missing).' document(s) manquant(s).'],
            WorkerStatus::ToReview => ['info', 'hourglass_top', 'En attente de vérification.', $count(DocumentStatus::ToReview).' document(s) à vérifier.'],
            WorkerStatus::InReview => ['info', 'hourglass_top', 'Vérification en cours.', $count(DocumentStatus::ToReview).' document(s) restant(s) à vérifier.'],
            default => null,
        };

        if (! $banner) {
            return null;
        }

        return [
            'tone' => $banner[0],
            'icon' => $banner[1],
            'title' => $banner[2],
            'text' => $banner[3],
            'showDocuments' => in_array($w->status, [WorkerStatus::ToReview, WorkerStatus::InReview, WorkerStatus::Incomplete, WorkerStatus::Rejected], true),
        ];
    }

    /**
     * Alertes de l’onglet. `action` : lien, dépôt d’une pièce ou demande de remplacement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alerts(string $tab): array
    {
        return match ($tab) {
            '' => [...$this->documentAlerts(), ...$this->conflictAlerts(), ...$this->missionAlerts()],
            'identite-documents' => $this->documentAlerts(),
            'disponibilites' => $this->conflictAlerts(),
            default => [],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documentAlerts(): array
    {
        $w = $this->worker;
        $alerts = [];

        foreach ($w->documents as $d) {
            /** @var WorkerDocument $d */
            $label = $d->type->label();
            $replace = ['type' => 'document', 'document' => $d->type->value];
            if ($d->status === DocumentStatus::Missing) {
                $alerts[] = $this->alert('warning', 'upload_file', 'Document manquant', $label, 'Ajouter', $replace);
            }
            if ($d->status === DocumentStatus::Rejected && str_contains(mb_strtolower((string) $d->rejection_reason), 'illisible')) {
                $alerts[] = $this->alert('danger', 'visibility_off', 'Document illisible', "{$label} — {$d->rejection_reason}", 'Remplacer', $replace);
            }
            if ($d->status === DocumentStatus::Expired) {
                $alerts[] = $this->alert('orange', 'event_busy', 'Document expiré', "{$label} depuis le ".$d->expires_at?->translatedFormat('d M Y'), 'Remplacer', $replace);
            }
        }
        if ($w->identity_mismatch) {
            $alerts[] = $this->alert('danger', 'person_alert', 'Incohérence d’identité', 'La date de naissance déclarée diffère de celle de la pièce d’identité.');
        }
        if ($w->possible_duplicate) {
            $alerts[] = $this->alert('warning', 'content_copy', 'Doublon potentiel', 'Un profil « Awa Koné Bamba » utilise le même numéro de téléphone secondaire.');
        }

        return $alerts;
    }

    /**
     * Périodes bloquantes qui chevauchent une affectation active.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conflictAlerts(): array
    {
        $alerts = [];
        $active = collect($this->worker->demo('assignments'))->whereIn('status', ['En cours', 'Confirmée', 'Acceptée', 'En attente d’acceptation', 'Proposée', 'Remplacement demandé']);

        foreach ($this->worker->periods as $p) {
            /** @var WorkerPeriod $p */
            if (! $p->type->blocksAssignment()) {
                continue;
            }
            foreach ($active as $a) {
                if ($a['start'] <= $p->ends_on->format('Y-m-d') && $a['end'] >= $p->starts_on->format('Y-m-d')) {
                    $alerts[] = $this->alert(
                        'danger',
                        'event_busy',
                        'Conflit de disponibilité',
                        $p->type->label().' du '.$p->starts_on->translatedFormat('d M Y').' au '.$p->ends_on->translatedFormat('d M Y')." alors que l’ouvrier est affecté à {$a['site']} (".mb_strtolower($a['status']).').',
                        'Demander un remplacement',
                        ['type' => 'replacement'],
                    );
                }
            }
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function missionAlerts(): array
    {
        $w = $this->worker;
        $base = "/ouvriers/{$w->slug}";
        $alerts = [];

        if (collect($w->demo('timesheets'))->contains('status', 'Contesté')) {
            $alerts[] = $this->alert('warning', 'schedule', 'Pointage contesté', 'Un pointage est contesté par le promoteur.', 'Voir les pointages', ['type' => 'link', 'href' => "{$base}/pointages"]);
        }
        if (collect($w->demo('payouts'))->contains('status', 'Échoué')) {
            $alerts[] = $this->alert('danger', 'payments', 'Paiement échoué', 'Un versement Mobile Money a échoué.', 'Voir les paiements', ['type' => 'link', 'href' => "{$base}/paiements"]);
        }
        if (collect($w->demo('replacements'))->contains(fn (array $c): bool => ! in_array($c['status'], ['Clôturé', 'Annulé'], true))) {
            $alerts[] = $this->alert('orange', 'swap_horiz', 'Remplacement en cours', 'Un workflow de remplacement est ouvert.', 'Suivre', ['type' => 'link', 'href' => "{$base}/remplacements"]);
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
     * Données de démonstration, avec les montants des versements calculés.
     *
     * @return array<string, mixed>
     */
    public function demo(): array
    {
        $w = $this->worker;
        $rate = $w->daily_rate;

        return [
            'assignments' => $w->demo('assignments'),
            'timesheets' => $w->demo('timesheets'),
            'payouts' => array_map(function (array $p) use ($rate): array {
                $gross = $p['days'] * $rate;
                $commission = (int) round($gross * $this->commissionRate / 100);

                return [...$p, 'gross' => $gross, 'commission' => $commission, 'net' => $gross - $commission];
            }, $w->demo('payouts')),
            'reviews' => $w->demo('reviews'),
            'replacements' => $w->demo('replacements'),
            'stats' => $w->demo('stats'),
        ];
    }

    /**
     * Ouvriers validés compatibles avec le dossier de remplacement ouvert, classés par score.
     *
     * Pondération : métier 20 %, compétences 15 %, expérience 10 %, disponibilité 20 %,
     * localisation 10 %, coût 10 %, note 10 %, chantiers similaires 5 %.
     *
     * @return array<int, array<string, mixed>>
     */
    public function replacementCandidates(): array
    {
        $w = $this->worker;
        $case = collect($w->demo('replacements'))->first(fn (array $c): bool => ! in_array($c['status'], ['Clôturé', 'Annulé', 'Refusé'], true));

        if (! $case || $case['step'] !== 4) {
            return [];
        }

        $pool = Worker::query()->whereKeyNot($w->id)->where('status', WorkerStatus::Validated)->get();
        $direct = $pool->filter(fn (Worker $o): bool => $o->trade === $w->trade || in_array($w->trade, $o->secondary_trades, true));
        $near = $pool->diff($direct)->filter(fn (Worker $o): bool => in_array($o->trade, $w->secondary_trades, true) || array_intersect($o->secondary_trades, $w->secondary_trades) || $o->trade === 'Manœuvre');
        $candidates = $direct->count() >= 3 ? $direct : $direct->merge($near);

        return $candidates->map(function (Worker $o) use ($w, $case): array {
            $trade = $o->trade === $w->trade ? 100 : (in_array($w->trade, $o->secondary_trades, true) ? 70 : 40);
            $skills = (int) round(count(array_intersect($o->skills, $w->skills)) / max(1, count($w->skills)) * 100) ?: 55;
            $experience = min(100, (int) round($o->experience_years / max(1, $w->experience_years) * 100));
            $availability = match ($o->availability) {
                WorkerAvailability::Available => 100, WorkerAvailability::PreAssigned => 55, default => 20
            };
            $location = $o->commune === $w->commune ? 100 : 65;
            $cost = max(30, 100 - (int) round(abs($o->daily_rate - $w->daily_rate) / max(1, $w->daily_rate) * 200));
            $rating = (int) round($o->rating / 5 * 100);
            $similar = 60 + crc32($o->slug.$case['site']) % 40;
            $score = (int) round($trade * 0.2 + $skills * 0.15 + $experience * 0.1 + $availability * 0.2 + $location * 0.1 + $cost * 0.1 + $rating * 0.1 + $similar * 0.05);

            return [
                'slug' => $o->slug, 'name' => $o->name, 'trade' => $o->trade, 'experienceYears' => $o->experience_years, 'commune' => $o->commune,
                'dailyRate' => $o->daily_rate, 'rating' => $o->rating, 'availability' => $o->availability->label(), 'score' => $score,
                'criteria' => [['Métier', $trade], ['Compétences', $skills], ['Expérience', $experience], ['Disponibilité', $availability], ['Localisation', $location], ['Coût', $cost], ['Note', $rating], ['Chantiers similaires', $similar]],
            ];
        })->sortByDesc('score')->take(4)->values()->all();
    }

    /**
     * Nombre de jours d’une période (bornes incluses).
     */
    public static function days(string $from, string $to): int
    {
        return max(1, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
    }
}
