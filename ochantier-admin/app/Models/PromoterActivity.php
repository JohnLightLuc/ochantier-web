<?php

namespace App\Models;

use App\Enums\ActivityAuthorKind;
use Database\Factories\PromoterActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Événement du journal d’activité d’un promoteur.
 */
class PromoterActivity extends Model
{
    /** @use HasFactory<PromoterActivityFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['occurred_at', 'author', 'author_kind', 'action', 'target', 'icon', 'tone', 'link'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'author_kind' => ActivityAuthorKind::class,
        ];
    }

    /**
     * @return BelongsTo<Promoter, $this>
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }
}
