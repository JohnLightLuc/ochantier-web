<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d’audit des paramètres : modification, auteur, date, section, ancienne et nouvelle valeur.
 * Le journal n’est jamais modifié et ne contient aucun secret.
 */
#[Fillable(['occurred_at', 'author', 'section', 'action', 'old_value', 'new_value'])]
class SettingChange extends Model
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

    public static function record(string $section, string $action, ?string $old = null, ?string $new = null): self
    {
        return self::create(['occurred_at' => now(), 'author' => auth()->user()?->name ?? 'Système', 'section' => $section, 'action' => $action, 'old_value' => $old, 'new_value' => $new]);
    }
}
