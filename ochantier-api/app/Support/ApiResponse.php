<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Réponse de succès de l’API : { message, data, meta? }. Le message est null quand il n’y a rien à afficher.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $headers
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            ...($meta === [] ? [] : ['meta' => $meta]),
        ], $status, $headers);
    }
}
