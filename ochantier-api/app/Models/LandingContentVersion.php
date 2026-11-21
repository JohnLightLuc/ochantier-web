<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version publiée du contenu du site public ; la plus récente est en ligne.
 */
class LandingContentVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['number', 'content', 'changed_sections', 'note', 'restored_from', 'published_by_admin_id', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['content' => 'array', 'changed_sections' => 'array', 'published_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'published_by_admin_id');
    }

    public static function current(): self
    {
        return self::query()->orderByDesc('number')->firstOrFail();
    }
}
