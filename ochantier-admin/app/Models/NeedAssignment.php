<?php

namespace App\Models;

use App\Enums\NeedAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ouvrier trouvé pour un poste d’une offre (matching ou ajout manuel), suivi jusqu’à sa validation.
 *
 * `worker_id` est nul pour les profils de démonstration qui n’existent pas dans le module Ouvriers.
 */
class NeedAssignment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['need_id', 'need_offer_id', 'worker_id', 'reference', 'worker_name', 'trade', 'commune', 'phone', 'daily_cost', 'status', 'source', 'selected_on', 'score', 'warnings', 'match_reference', 'contacted_on', 'channel', 'reason', 'replaced_by', 'replaces'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NeedAssignmentStatus::class,
            'selected_on' => 'date',
            'contacted_on' => 'date',
        ];
    }

    /**
     * Prochaine référence d’affectation (« AFF-2026-1234 »), commune aux besoins et aux offres des promoteurs.
     */
    public static function nextReference(): string
    {
        $number = fn (?string $r): int => (int) preg_replace('/\D/', '', substr((string) $r, -4));
        $last = max(
            static::query()->pluck('reference')->map($number)->max() ?? 0,
            PromoterOfferProfile::query()->whereNotNull('assignment_reference')->pluck('assignment_reference')->map($number)->max() ?? 0,
        );

        return 'AFF-2026-'.($last + 1);
    }

    /**
     * @return BelongsTo<NeedOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(NeedOffer::class, 'need_offer_id');
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
