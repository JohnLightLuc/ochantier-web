<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Événement du journal d’audit du Matching.
 */
class MatchingAudit extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['occurred_at', 'author', 'action', 'subject', 'detail'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Inscrit une action au journal d’audit du Matching.
     */
    public static function record(string $action, ?string $subject = null, ?string $detail = null): self
    {
        return static::create(['occurred_at' => now(), 'author' => auth()->user()?->name ?? 'Système', 'action' => $action, 'subject' => $subject, 'detail' => $detail]);
    }
}
