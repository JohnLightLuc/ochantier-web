<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Résolution d’un contrôle de rapprochement : écart justifié (gap) ou exclusion d’un doublon confirmée (duplicate).
 * La donnée source du module Paiements n’est jamais modifiée.
 */
#[Fillable(['reference', 'kind', 'reason', 'author'])]
class FinanceResolution extends Model
{
    public const GAP = 'gap';

    public const DUPLICATE = 'duplicate';
}
