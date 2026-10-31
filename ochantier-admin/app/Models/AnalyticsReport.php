<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rapport généré par le module Rapports (bibliothèque) : type, format, filtres et rythme utilisés.
 * Le fichier est reconstruit à la demande à partir des données actuelles de la plateforme.
 */
class AnalyticsReport extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['kind', 'name', 'format', 'rows', 'size', 'generated_on', 'author', 'filters', 'rhythm'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_on' => 'date',
        ];
    }
}
