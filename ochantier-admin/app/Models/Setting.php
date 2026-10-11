<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Valeur enregistrée d’un paramètre de la plateforme (clé du paramètre, valeur JSON). Les paramètres absents
 * prennent leur valeur par défaut (config/admin.php ou définition du paramètre).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
