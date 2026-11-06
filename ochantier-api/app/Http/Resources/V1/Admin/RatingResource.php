<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\RatingScope;
use App\Http\Resources\V1\Resource;
use App\Models\Rating;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des avis.
 *
 * @mixin Rating
 */
class RatingResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = $this->authorUser;

        return [
            'id' => $this->id,
            'scope' => $this->status($this->rating_scope),
            'target' => $this->rating_scope === RatingScope::Individuel
                ? ['kind' => 'worker', 'id' => $this->worker_id, 'name' => $this->worker_name ?? 'Ouvrier']
                : ['kind' => 'team', 'id' => null, 'name' => 'Équipe · '.$this->site?->label],
            'score' => $this->overall_score,
            'satisfaction_tag' => $this->satisfaction_tag,
            'comment' => $this->comment,
            'site' => $this->site ? ['id' => $this->site->id, 'title' => $this->site->label] : null,
            'promoter' => ['id' => $this->site?->promoter_profile_id, 'name' => trim((string) $this->promoter_name) ?: 'Promoteur'],
            'author' => $author ? (trim($author->first_name.' '.$author->last_name) ?: $author->phone_number) : null,
            'status' => $this->status($this->status),
            'moderation' => $this->moderation_status ? [...$this->status($this->moderation_status), 'reason' => $this->moderation_reason, 'by' => $this->moderatedByAdmin?->fullName()] : null,
            'verification' => $this->status($this->verification_status),
            'suspected' => $this->fraud_suspected,
            'reported_at' => $this->reported_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'version' => $this->version,
        ];
    }
}
