<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Vue enregistrée : filtres, base temporelle et période d’analyse.
 */
#[Fillable(['name', 'filters', 'basis', 'period', 'author', 'shared'])]
class FinanceView extends Model
{
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
