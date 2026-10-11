<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Rapport financier généré (PDF imprimable, Excel ou CSV).
 */
#[Fillable(['name', 'format', 'period', 'basis', 'author', 'rows', 'generated_at'])]
class FinanceReport extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }
}
