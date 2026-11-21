<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * L’API O’CHANTIER ne peut pas recevoir le formulaire pour l’instant : le visiteur est invité à réessayer.
 */
class ServiceUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'Le service est momentanément indisponible. Réessayez dans quelques minutes.'], 503);
    }
}
