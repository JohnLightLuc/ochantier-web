<?php

namespace App\Models;

use App\Enums\ProgressDeclarationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Progression métier déclarée (distincte de la progression calendaire).
 */
#[Fillable([
    'mission_id', 'percent', 'comment', 'declared_by_user_id', 'declared_at', 'status', 'verified_by_admin_id',
    'verified_at', 'client_uuid',
])]
#[WithoutTimestamps]
class ProgressDeclaration extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'declared_at' => 'datetime',
            'status' => ProgressDeclarationStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function declaredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function verifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
