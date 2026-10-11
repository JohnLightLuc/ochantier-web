<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d’Avis & litiges : événements d’un avis, d’une réclamation ou d’un litige (référence du dossier) et
 * actions globales (exports). L’ensemble constitue le journal d’audit du module.
 */
#[Fillable(['subject_reference', 'occurred_at', 'author', 'category', 'action', 'old_value', 'new_value', 'comment', 'origin'])]
class QualityActivity extends Model
{
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
     * Trace une action (par défaut : l’utilisateur connecté avec son rôle, depuis le Back-office).
     */
    public static function record(?string $reference, string $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, ?string $author = null, ?string $origin = null): self
    {
        return self::create([
            'subject_reference' => $reference,
            'occurred_at' => now(),
            'author' => $author ?? (auth()->user()?->name ?? 'Système').' · '.config('admin.quality.role'),
            'category' => $category,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'comment' => $comment,
            'origin' => $origin ?? 'Back-office · IP '.(request()->ip() ?? '—'),
        ]);
    }
}
