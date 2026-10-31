<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Clôture d’une période mensuelle : Ouverte → En préparation → À contrôler → Clôturée, réouverture sur autorisation.
 * Chaque clôture fige un instantané versionné ; les instantanés précédents sont conservés dans l’historique.
 */
class FinanceClosing extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['month', 'status', 'version', 'snapshot', 'history', 'reason'];

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
