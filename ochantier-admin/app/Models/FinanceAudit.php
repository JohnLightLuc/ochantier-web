<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal d’audit de Finance & Reporting : consultations sensibles, exports, rapports, vues, clôtures,
 * réouvertures et résolutions d’écart.
 */
class FinanceAudit extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['occurred_at', 'author', 'category', 'action', 'old_value', 'new_value', 'comment', 'origin'];

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
     * Trace une action de l’utilisateur connecté (avec son rôle financier).
     */
    public static function record(string $action, string $scope, ?string $old = null, ?string $new = null, ?string $comment = null, string $category = 'modification'): self
    {
        return self::create([
            'occurred_at' => now(),
            'author' => (auth()->user()?->name ?? 'Système').' · '.config('admin.finance.role'),
            'category' => $category,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'comment' => $scope.($comment ? " · {$comment}" : ''),
            'origin' => 'Back-office · IP '.(request()->ip() ?? '—'),
        ]);
    }
}
