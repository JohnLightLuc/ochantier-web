<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Programmation d’un rapport : génération automatique (hebdomadaire, mensuelle, trimestrielle) envoyée aux
 * destinataires, active ou suspendue.
 */
#[Fillable(['kind', 'frequency', 'next_run', 'recipients', 'format', 'status'])]
class AnalyticsSchedule extends Model
{
    public const FREQUENCIES = ['Hebdomadaire', 'Mensuel', 'Trimestriel'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_run' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'Actif';
    }
}
