<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vue enregistrée : filtres, base temporelle et période d’analyse.
 */
class FinanceView extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'filters', 'basis', 'period', 'author', 'shared'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'shared' => 'array',
        ];
    }
}
