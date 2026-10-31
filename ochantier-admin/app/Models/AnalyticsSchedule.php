<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Programmation d’un rapport : génération automatique (hebdomadaire, mensuelle, trimestrielle) envoyée aux
 * destinataires, active ou suspendue.
 */
class AnalyticsSchedule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['kind', 'frequency', 'next_run', 'recipients', 'format', 'status'];

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
