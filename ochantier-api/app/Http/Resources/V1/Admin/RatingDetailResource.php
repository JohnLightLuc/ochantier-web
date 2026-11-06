<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Rating;
use App\Models\RatingVersion;
use App\Support\Admin\RatingModeration;
use Illuminate\Http\Request;

/**
 * Fiche d’un avis : versions précédentes, journal de modération et actions possibles.
 *
 * @mixin Rating
 */
class RatingDetailResource extends RatingResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'versions' => $this->versions()->orderByDesc('version')->get()->map(fn (RatingVersion $version) => [
                'version' => $version->version,
                'score' => $version->overall_score,
                'satisfaction_tag' => $version->satisfaction_tag,
                'comment' => $version->comment,
                'date' => $version->created_at?->toIso8601String(),
            ])->values()->all(),
            'history' => AuditLog::query()
                ->where('subject_type', $this->resource->getMorphClass())
                ->where('subject_id', $this->id)
                ->latest()
                ->with('actor')
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'reason' => $log->after_values['reason'] ?? null,
                    'by' => $log->actor instanceof Admin ? $log->actor->fullName() : null,
                    'date' => $log->created_at?->toIso8601String(),
                ])->values()->all(),
            'can' => app(RatingModeration::class)->actions($this->resource),
        ];
    }
}
