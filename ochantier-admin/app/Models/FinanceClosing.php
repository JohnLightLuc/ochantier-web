<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Clôture d’une période mensuelle : Ouverte → En préparation → À contrôler → Clôturée, réouverture sur autorisation.
 * Chaque clôture fige un instantané versionné ; les instantanés précédents sont conservés dans l’historique.
 */
#[Fillable(['month', 'status', 'version', 'snapshot', 'history', 'reason'])]
class FinanceClosing extends Model
{
    public const STATUSES = ['Ouverte', 'En préparation', 'À contrôler', 'Clôturée', 'Rouverte avec autorisation'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'history' => 'array',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'Clôturée';
    }
}
