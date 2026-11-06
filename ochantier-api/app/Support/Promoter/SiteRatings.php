<?php

namespace App\Support\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\RatingScope;
use App\Enums\RatingStatus;
use App\Enums\SiteStatus;
use App\Models\Assignment;
use App\Models\Rating;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Évaluations du promoteur : chaque ouvrier dont la mission est terminée, puis l’« Équipe globale » à la fin du
 * chantier. Une note de 1 à 5 avec une satisfaction (tag) et un commentaire facultatif, publiée aussitôt et
 * modifiable pendant config('admin.quality.review_window') jours ; chaque modification est historisée.
 */
class SiteRatings
{
    /**
     * Satisfactions proposées par note, pour un ouvrier (individuel) et pour l’équipe.
     *
     * @var array<string, array<int, list<string>>>
     */
    public const TAGS = [
        'individuel' => [
            1 => ['Absences répétées', 'Travail bâclé', 'Non-respect des consignes', 'Comportement inadapté'],
            2 => ['Retards fréquents', 'Qualité insuffisante', 'Manque de rigueur', 'Communication difficile'],
            3 => ['Travail correct', 'Quelques retards', 'Doit gagner en autonomie', 'Finitions à améliorer'],
            4 => ['Ponctuel', 'Travail soigné', 'Respect des consignes', 'Bonne communication'],
            5 => ['Travail exceptionnel', 'Très professionnel', 'Grande autonomie', 'À recommander'],
        ],
        'equipe' => [
            1 => ['Délais non respectés', 'Chantier désorganisé', 'Qualité très insuffisante', 'Sécurité négligée'],
            2 => ['Retards importants', 'Coordination difficile', 'Finitions à reprendre', 'Chantier peu propre'],
            3 => ['Résultat correct', 'Quelques retards', 'Coordination à améliorer', 'Finitions moyennes'],
            4 => ['Délais respectés', 'Bonne coordination', 'Chantier propre', 'Travail de qualité'],
            5 => ['Équipe exemplaire', 'Finitions impeccables', 'Avance sur le planning', 'À recommander'],
        ],
    ];

    /**
     * Libellé de chaque note.
     *
     * @var array<int, string>
     */
    public const LABELS = [1 => 'Très insatisfait', 2 => 'Insatisfait', 3 => 'Correct', 4 => 'Satisfait', 5 => 'Excellent'];

    /**
     * Missions dont les ouvriers peuvent être évalués : acompte payé, pas annulées.
     */
    private const RATED_MISSIONS = [MissionStatus::Confirmee, MissionStatus::EnCours, MissionStatus::Suspendue, MissionStatus::Terminee];

    public function __construct(private SiteAttendance $attendance) {}

    /**
     * Carte « Évaluer l’équipe » du détail chantier : ouvriers à noter ou notés, puis la note d’équipe.
     *
     * @return array<string, mixed>
     */
    public function summary(Site $site, User $author): array
    {
        $ended = $site->status === SiteStatus::Termine;
        $assignments = $this->rateableAssignments($site);
        $ratings = Rating::query()
            ->where('author_user_id', $author->getKey())
            ->where('site_id', $site->getKey())
            ->get();
        $byAssignment = $ratings->where('rating_scope', RatingScope::Individuel)->keyBy('assignment_id');
        $teamRating = $ratings->firstWhere('rating_scope', RatingScope::Equipe);

        $workers = $assignments->map(function (Assignment $assignment) use ($byAssignment): array {
            $rating = $byAssignment->get($assignment->id);
            $member = $this->attendance->member($assignment);

            return [
                ...collect($member)->only(['assignment_id', 'worker_id', 'name', 'initials', 'photo_url', 'trade', 'role'])->all(),
                'mission_ended_on' => $this->endOf($assignment)->toDateString(),
                'subtitle' => $member['role'].' · mission terminée le '.$this->date($this->endOf($assignment)),
                'rating' => $rating ? $this->rating($rating) : null,
                'can_rate' => $rating === null,
            ];
        })->values();

        $rated = $workers->whereNotNull('rating')->count();
        $teamCount = $this->teamCount($site);

        return [
            'show' => $workers->isNotEmpty() || $ended,
            'title' => $ended ? 'Évaluer l’équipe' : 'Missions terminées · à évaluer',
            'subtitle' => $ended
                ? 'Chantier terminé — notez chaque ouvrier et l’équipe ('.$rated.'/'.$workers->count().' notés)'
                : 'Notez les ouvriers dont la mission est terminée ('.$rated.'/'.$workers->count().')',
            'rated_count' => $rated,
            'total' => $workers->count(),
            'workers' => $workers->all(),
            'team' => $ended ? [
                'title' => 'Équipe globale',
                'subtitle' => $teamCount.' ouvrier'.($teamCount > 1 ? 's' : '').' · '.$site->label,
                'rating' => $teamRating ? $this->rating($teamRating) : null,
                'can_rate' => $teamRating === null,
            ] : null,
        ];
    }

    /**
     * Note d’un ouvrier (individuel, avec assignment_id) ou de l’équipe globale (equipe), publiée aussitôt.
     *
     * @param  array{scope: string, assignment_id?: string|null, score: int, satisfaction_tag: string, comment?: string|null}  $data
     */
    public function rate(Site $site, User $author, array $data): Rating
    {
        $scope = RatingScope::from($data['scope']);

        return DB::transaction(function () use ($site, $author, $data, $scope): Rating {
            $attributes = $scope === RatingScope::Individuel
                ? $this->individualTarget($site, $author, (string) ($data['assignment_id'] ?? ''))
                : $this->teamTarget($site, $author);

            $rating = Rating::query()->create([
                ...$attributes,
                'rating_scope' => $scope,
                'site_id' => $site->getKey(),
                'author_user_id' => $author->getKey(),
                'overall_score' => $data['score'],
                'satisfaction_tag' => $data['satisfaction_tag'],
                'comment' => $this->comment($data['comment'] ?? null),
                'status' => RatingStatus::Publie,
                'published_at' => now(),
                'editable_until' => now()->addDays((int) config('admin.quality.review_window', 30)),
            ]);

            if ($rating->worker_id) {
                $this->adjustWorkerAverage($rating->worker_id, $rating->overall_score);
            }

            return $rating;
        });
    }

    /**
     * « Modifier » un avis : la version précédente est conservée dans rating_versions.
     *
     * @param  array{score: int, satisfaction_tag: string, comment?: string|null}  $data
     */
    public function update(Rating $rating, array $data): Rating
    {
        if ($rating->status !== RatingStatus::Publie || ($rating->editable_until && $rating->editable_until->isPast())) {
            throw ValidationException::withMessages(['rating' => 'Cet avis ne peut plus être modifié.']);
        }

        return DB::transaction(function () use ($rating, $data): Rating {
            $previous = $rating->overall_score;

            $rating->versions()->create([
                'version' => $rating->version,
                'overall_score' => $rating->overall_score,
                'criteria_scores' => $rating->criteria_scores ?? [],
                'comment' => $rating->comment,
                'satisfaction_tag' => $rating->satisfaction_tag,
            ]);

            $rating->update([
                'overall_score' => $data['score'],
                'satisfaction_tag' => $data['satisfaction_tag'],
                'comment' => $this->comment($data['comment'] ?? null),
                'version' => $rating->version + 1,
            ]);

            if ($rating->worker_id && $previous !== $rating->overall_score) {
                $this->adjustWorkerAverage($rating->worker_id, $rating->overall_score, $previous);
            }

            return $rating;
        });
    }

    /**
     * « Notes & avis » du profil : avis publiés par le promoteur, moyenne et répartition par note.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function overview(User $author, string $filter = 'all'): array
    {
        $ratings = Rating::query()
            ->where('author_user_id', $author->getKey())
            ->where('status', RatingStatus::Publie)
            ->with(['site', 'assignment.need.trade', 'assignment.worker.photoMedia', 'assignment.worker.workerTrades.trade', 'assignment.replacesAssignment.worker'])
            ->latest('published_at')
            ->get();

        $count = $ratings->count();
        $average = $count ? round($ratings->avg('overall_score'), 1) : null;
        $teamCounts = $this->teamCounts($ratings->where('rating_scope', RatingScope::Equipe)->pluck('site_id')->filter()->unique()->values());

        $list = $ratings
            ->filter(fn (Rating $rating) => $filter === 'all' || $rating->rating_scope->value === $filter)
            ->map(fn (Rating $rating) => $this->overviewItem($rating, $teamCounts))
            ->values()
            ->all();

        return [
            'data' => $list,
            'meta' => [
                'average' => $average,
                'average_label' => $average !== null ? str_replace('.', ',', number_format($average, 1)) : '—',
                'count' => $count,
                'count_label' => $count.' avis publié'.($count > 1 ? 's' : ''),
                'tile_label' => $count ? $count.' avis · '.str_replace('.', ',', number_format($average, 1)).' ★' : 'Aucun avis',
                'distribution' => collect([5, 4, 3, 2, 1])->map(fn (int $score) => [
                    'score' => $score,
                    'count' => $c = $ratings->where('overall_score', $score)->count(),
                    'percent' => $count ? (int) round($c / $count * 100) : 0,
                ])->all(),
                'counts' => [
                    'all' => $count,
                    'individuel' => $ratings->where('rating_scope', RatingScope::Individuel)->count(),
                    'equipe' => $ratings->where('rating_scope', RatingScope::Equipe)->count(),
                ],
            ],
        ];
    }

    /**
     * Avis laissés sur des chantiers (fiche promoteur du Back Office) : statut, modération et auteur en plus.
     *
     * @param  Collection<int, string>  $siteIds
     * @param  list<RatingStatus>  $statuses
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function forSites(Collection $siteIds, array $statuses): array
    {
        $ratings = Rating::query()
            ->whereIn('site_id', $siteIds)
            ->whereIn('status', $statuses)
            ->with(['site', 'authorUser', 'assignment.need.trade', 'assignment.worker.photoMedia', 'assignment.worker.workerTrades.trade', 'assignment.replacesAssignment.worker'])
            ->latest('published_at')
            ->get();

        $published = $ratings->where('status', RatingStatus::Publie);
        $count = $published->count();
        $average = $count ? round($published->avg('overall_score'), 1) : null;
        $teamCounts = $this->teamCounts($ratings->where('rating_scope', RatingScope::Equipe)->pluck('site_id')->filter()->unique()->values());

        return [
            'data' => $ratings->map(fn (Rating $rating) => [
                ...$this->overviewItem($rating, $teamCounts),
                'status' => ['value' => $rating->status->value, 'label' => $rating->status->label()],
                'moderation' => $rating->moderation_status ? ['value' => $rating->moderation_status->value, 'label' => $rating->moderation_status->label(), 'reason' => $rating->moderation_reason] : null,
                'author' => $rating->authorUser ? trim($rating->authorUser->first_name.' '.$rating->authorUser->last_name) : null,
            ])->values()->all(),
            'meta' => [
                'average' => $average,
                'count' => $count,
                'hidden_count' => $ratings->where('status', RatingStatus::Masque)->count(),
                'distribution' => collect([5, 4, 3, 2, 1])->map(fn (int $score) => [
                    'score' => $score,
                    'count' => $c = $published->where('overall_score', $score)->count(),
                    'percent' => $count ? (int) round($c / $count * 100) : 0,
                ])->all(),
            ],
        ];
    }

    /**
     * Satisfactions et libellés des notes, pour la feuille « Noter… ».
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'labels' => self::LABELS,
            'tags' => self::TAGS,
            'questions' => [
                'positive' => 'Qu’avez-vous le plus apprécié ?',
                'neutral' => 'Qu’est-ce qui résume le mieux ?',
                'negative' => 'Qu’est-ce qui n’a pas été ?',
            ],
            'review_window_days' => (int) config('admin.quality.review_window', 30),
        ];
    }

    /**
     * Affectations de ce chantier dont la mission est terminée ou la date de fin passée.
     *
     * @return Collection<int, Assignment>
     */
    public function rateableAssignments(Site $site): Collection
    {
        $today = CarbonImmutable::today();

        return Assignment::query()
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey())->whereIn('status', self::RATED_MISSIONS))
            ->whereNot('status', AssignmentStatus::Annulee)
            ->with([...SiteAttendance::ASSIGNMENT_WITH, 'mission'])
            ->get()
            ->filter(fn (Assignment $assignment) => match (true) {
                $assignment->status === AssignmentStatus::Remplacee => $assignment->actual_start_at !== null,
                $assignment->status === AssignmentStatus::Terminee, $assignment->mission->status === MissionStatus::Terminee => true,
                default => $assignment->planned_end_date->lessThan($today),
            })
            ->pipe(fn (Collection $assignments) => $this->attendance->ordered($assignments))
            ->values();
    }

    /**
     * Avis tel qu’affiché (étoiles, satisfaction et sa tonalité, commentaire, modification possible).
     *
     * @return array<string, mixed>
     */
    public function rating(Rating $rating): array
    {
        $score = $rating->overall_score;

        return [
            'id' => $rating->id,
            'scope' => ['value' => $rating->rating_scope->value, 'label' => $rating->rating_scope->label()],
            'score' => $score,
            'stars' => str_repeat('★', $score).str_repeat('☆', 5 - $score),
            'label' => self::LABELS[$score],
            'satisfaction_tag' => $rating->satisfaction_tag,
            'tone' => $this->tone($score),
            'comment' => $rating->comment,
            'summary' => trim(($rating->satisfaction_tag ?? '').($rating->comment ? ' — '.$rating->comment : '')),
            'published_at' => $rating->published_at?->toIso8601String(),
            'date_label' => $rating->published_at ? $this->date($rating->published_at) : null,
            'version' => $rating->version,
            'editable' => $rating->status === RatingStatus::Publie && ! $rating->editable_until?->isPast(),
            'editable_until' => $rating->editable_until?->toIso8601String(),
        ];
    }

    public function tone(int $score): string
    {
        return match (true) {
            $score >= 4 => 'positive',
            $score === 3 => 'neutral',
            default => 'negative',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function individualTarget(Site $site, User $author, string $assignmentId): array
    {
        /** @var Assignment|null $assignment */
        $assignment = $this->rateableAssignments($site)->firstWhere('id', $assignmentId);

        if (! $assignment) {
            throw ValidationException::withMessages(['assignment_id' => 'Vous pourrez noter cet ouvrier à la fin de sa mission.']);
        }

        if (Rating::query()->where('assignment_id', $assignment->getKey())->where('author_user_id', $author->getKey())->exists()) {
            throw ValidationException::withMessages(['assignment_id' => 'Vous avez déjà noté cet ouvrier : modifiez votre avis.']);
        }

        return ['mission_id' => $assignment->mission_id, 'assignment_id' => $assignment->getKey(), 'worker_id' => $assignment->worker_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamTarget(Site $site, User $author): array
    {
        if ($site->status !== SiteStatus::Termine) {
            throw ValidationException::withMessages(['scope' => 'Vous pourrez noter l’équipe à la fin du chantier.']);
        }

        if ($site->ratings()->where('rating_scope', RatingScope::Equipe)->where('author_user_id', $author->getKey())->exists()) {
            throw ValidationException::withMessages(['scope' => 'Vous avez déjà noté l’équipe : modifiez votre avis.']);
        }

        $team = Team::query()->whereIn('offer_id', $site->offers()->select('id'))->first();

        return ['team_id' => $team?->getKey()];
    }

    /**
     * Met à jour la note moyenne de l’ouvrier : ajout d’une note, ou remplacement de $previous par $score.
     */
    private function adjustWorkerAverage(string $workerId, int $score, ?int $previous = null): void
    {
        $worker = Worker::query()->whereKey($workerId)->lockForUpdate()->firstOrFail();
        $count = (int) $worker->rating_count;
        $total = $worker->rating_avg !== null ? (float) $worker->rating_avg * $count : 0.0;

        [$total, $count] = $previous === null || $count === 0
            ? [$total + $score, $count + 1]
            : [$total - $previous + $score, $count];

        $worker->update(['rating_avg' => round(min(5, max(1, $total / $count)), 2), 'rating_count' => $count]);
    }

    /**
     * Retire une note de la moyenne de l’ouvrier (avis masqué). La moyenne peut reposer sur des notes antérieures sans
     * avis en base : on ajuste, on ne recalcule pas tout.
     */
    public function removeFromWorkerAverage(string $workerId, int $score): void
    {
        $worker = Worker::query()->whereKey($workerId)->lockForUpdate()->firstOrFail();
        $count = (int) $worker->rating_count;

        if ($count <= 1) {
            $worker->update(['rating_avg' => null, 'rating_count' => 0]);

            return;
        }

        $worker->update(['rating_avg' => round(min(5, max(1, ((float) $worker->rating_avg * $count - $score) / ($count - 1))), 2), 'rating_count' => $count - 1]);
    }

    /**
     * Remet une note dans la moyenne de l’ouvrier (avis rétabli).
     */
    public function addToWorkerAverage(string $workerId, int $score): void
    {
        $this->adjustWorkerAverage($workerId, $score);
    }

    /**
     * @param  Collection<string, int>  $teamCounts
     * @return array<string, mixed>
     */
    private function overviewItem(Rating $rating, Collection $teamCounts): array
    {
        $site = $rating->site;
        $siteSummary = $site ? ['id' => $site->id, 'title' => $site->label] : null;

        if ($rating->rating_scope === RatingScope::Equipe) {
            $count = (int) $teamCounts->get($rating->site_id, 0);

            return [
                ...$this->rating($rating),
                'kind' => 'team',
                'name' => 'Équipe · '.$site?->label,
                'subtitle' => $count.' ouvrier'.($count > 1 ? 's' : ''),
                'photo_url' => null,
                'site' => $siteSummary,
            ];
        }

        $member = $this->attendance->member($rating->assignment);

        return [
            ...$this->rating($rating),
            'kind' => 'worker',
            'name' => $member['name'],
            'subtitle' => $member['role'].' · '.$site?->label,
            'photo_url' => $member['photo_url'],
            'assignment_id' => $rating->assignment_id,
            'site' => $siteSummary,
        ];
    }

    private function teamCount(Site $site): int
    {
        return (int) $this->teamCounts(collect([$site->getKey()]))->get($site->getKey(), 0);
    }

    /**
     * Nombre d’ouvriers distincts affectés à chaque chantier.
     *
     * @param  Collection<int, string>  $siteIds
     * @return Collection<string, int>
     */
    private function teamCounts(Collection $siteIds): Collection
    {
        if ($siteIds->isEmpty()) {
            return collect();
        }

        return Assignment::query()
            ->join('missions', 'missions.id', '=', 'assignments.mission_id')
            ->whereIn('missions.site_id', $siteIds)
            ->whereIn('missions.status', self::RATED_MISSIONS)
            ->whereNot('assignments.status', AssignmentStatus::Annulee)
            ->groupBy('missions.site_id')
            ->selectRaw('missions.site_id, count(distinct assignments.worker_id) as workers')
            ->pluck('workers', 'site_id')
            ->map(fn ($count) => (int) $count);
    }

    private function endOf(Assignment $assignment): CarbonImmutable
    {
        $end = CarbonImmutable::parse($assignment->actual_end_at ?? $assignment->planned_end_date)->startOfDay();

        return $end->greaterThan(CarbonImmutable::today()) ? CarbonImmutable::today() : $end;
    }

    private function comment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : $comment;
    }

    private function date(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
