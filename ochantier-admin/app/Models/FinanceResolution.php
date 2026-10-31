<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Résolution d’un contrôle de rapprochement : écart justifié (gap) ou exclusion d’un doublon confirmée (duplicate).
 * La donnée source du module Paiements n’est jamais modifiée.
 */
class FinanceResolution extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['reference', 'kind', 'reason', 'author'];

    public const GAP = 'gap';

    public const DUPLICATE = 'duplicate';
}
