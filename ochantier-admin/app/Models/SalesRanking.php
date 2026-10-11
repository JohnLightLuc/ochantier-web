<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * État du classement mensuel des commerciaux : clôture et publication dans l’Application Commercial.
 */
#[Fillable(['month', 'closed_at', 'published'])]
class SalesRanking extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'published' => 'boolean',
        ];
    }
}
