<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Appréciation écrite sur un commercial par le Back Office.
 */
#[Fillable([
    'commercial_id', 'author_admin_id', 'body',
])]
class CommercialAppreciation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
