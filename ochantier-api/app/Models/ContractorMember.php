<?php

namespace App\Models;

use App\Enums\ContractorMemberRole;
use App\Enums\ContractorMemberStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Collaborateur du compte d’un entrepreneur (invité, actif ou retiré), avec son rôle.
 */
class ContractorMember extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contractor_id', 'user_id', 'role', 'status', 'invited_by_user_id', 'joined_at', 'revoked_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ContractorMemberRole::class,
            'status' => ContractorMemberStatus::class,
            'joined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
