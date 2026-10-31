<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rapport financier généré (PDF imprimable, Excel ou CSV).
 */
class FinanceReport extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'format', 'period', 'basis', 'author', 'rows', 'generated_at'];

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
