<?php

namespace App\Support\Admin;

use App\Enums\RatingModerationStatus;
use App\Enums\RatingStatus;
use App\Enums\RatingVerificationStatus;
use App\Models\Admin;
use App\Models\Rating;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\SiteRatings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modération des avis, toujours motivée. La note et le texte de l’auteur ne changent jamais : on signale, on maintient,
 * on masque ou on rétablit. Un avis masqué sort de la moyenne de l’ouvrier, et le promoteur en est notifié.
 */
class RatingModeration
{
    public function __construct(private SiteRatings $ratings, private PromoterNotifier $notifier) {}

    /**
     * @return array{report: bool, keep: bool, hide: bool, restore: bool}
     */
    public function actions(Rating $rating): array
    {
        $published = $rating->status === RatingStatus::Publie;
        $reported = $rating->moderation_status === RatingModerationStatus::Signale;

        return ['report' => $published && ! $reported, 'keep' => $published && $reported, 'hide' => $published, 'restore' => $rating->status === RatingStatus::Masque];
    }

    public function report(Rating $rating, string $reason, Admin $admin, Request $request): void
    {
        $this->decide($rating, 'report', 'Cet avis ne peut pas être signalé.', $admin, $request, 'ratings.reported', $reason, ['reported_at' => now(), 'moderation_status' => RatingModerationStatus::Signale, 'moderation_reason' => $reason]);
    }

    public function keep(Rating $rating, string $reason, Admin $admin, Request $request): void
    {
        $this->decide($rating, 'keep', 'Cet avis n’est pas signalé.', $admin, $request, 'ratings.kept', $reason, ['moderation_status' => RatingModerationStatus::Maintenu, 'moderation_reason' => $reason]);
    }

    public function hide(Rating $rating, string $reason, Admin $admin, Request $request): void
    {
        $this->decide($rating, 'hide', 'Cet avis n’est pas publié.', $admin, $request, 'ratings.hidden', $reason, ['status' => RatingStatus::Masque, 'moderation_status' => RatingModerationStatus::Masque, 'moderation_reason' => $reason]);
        $this->notifier->ratingModerated($rating->refresh(), 'Votre avis a été masqué', 'Votre avis sur '.$rating->site?->label.' n’est plus visible. Motif : '.$reason);
    }

    public function restore(Rating $rating, string $reason, Admin $admin, Request $request): void
    {
        $this->decide($rating, 'restore', 'Cet avis n’est pas masqué.', $admin, $request, 'ratings.restored', $reason, ['status' => RatingStatus::Publie, 'moderation_status' => RatingModerationStatus::Maintenu, 'moderation_reason' => $reason]);
        $this->notifier->ratingModerated($rating->refresh(), 'Votre avis est de nouveau visible', 'Votre avis sur '.$rating->site?->label.' a été rétabli.');
    }

    /**
     * Marque un avis comme suspect, ou lève le doute après contrôle. Sans effet sur sa publication.
     */
    public function suspect(Rating $rating, bool $suspected, string $reason, Admin $admin, Request $request): void
    {
        $rating->update(['fraud_suspected' => $suspected, 'verification_status' => $suspected ? RatingVerificationStatus::Suspect : RatingVerificationStatus::Verifie]);
        AdminAudit::record($admin, 'ratings', $suspected ? 'ratings.suspected' : 'ratings.cleared', $request, $rating, null, ['reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function decide(Rating $rating, string $action, string $refusal, Admin $admin, Request $request, string $event, string $reason, array $values): void
    {
        DB::transaction(function () use ($rating, $action, $refusal, $admin, $request, $event, $reason, $values): void {
            $locked = Rating::query()->lockForUpdate()->findOrFail($rating->getKey());

            if (! $this->actions($locked)[$action]) {
                throw ValidationException::withMessages(['rating' => $refusal]);
            }

            $before = ['status' => $locked->status->value, 'moderation_status' => $locked->moderation_status?->value];
            $locked->update([...$values, 'moderated_by_admin_id' => $admin->getKey()]);

            if ($locked->worker_id && $action === 'hide') {
                $this->ratings->removeFromWorkerAverage($locked->worker_id, $locked->overall_score);
            }

            if ($locked->worker_id && $action === 'restore') {
                $this->ratings->addToWorkerAverage($locked->worker_id, $locked->overall_score);
            }

            AdminAudit::record($admin, 'ratings', $event, $request, $locked, $before, ['status' => $locked->status->value, 'moderation_status' => $locked->moderation_status?->value, 'reason' => $reason]);
        });
    }
}
