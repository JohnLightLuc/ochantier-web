<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brouillon unique du contenu du site public, modifié depuis le Back Office puis publié.
 */
class LandingContentDraft extends Model
{
    public $incrementing = false;

    public const CREATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'content', 'restored_from', 'updated_by_admin_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['content' => 'array', 'updated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }
}
