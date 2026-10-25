<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parrainage : invitation d’un proche à rejoindre O’CHANTIER (SMS) ou inscription par le lien du parrain.
 */
class Referral extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'referrer_profile_id', 'channel', 'invited_phone', 'invited_name', 'referred_user_id', 'status', 'sent_at',
        'registered_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'sent_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PromoterProfile, $this>
     */
    public function referrerProfile(): BelongsTo
    {
        return $this->belongsTo(PromoterProfile::class, 'referrer_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
