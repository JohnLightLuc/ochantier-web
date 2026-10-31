<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * État du classement mensuel des commerciaux : clôture et publication dans l’Application Commercial.
 */
class SalesRanking extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['month', 'closed_at', 'published'];

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
