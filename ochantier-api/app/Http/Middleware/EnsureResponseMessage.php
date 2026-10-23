<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajoute « message: null » aux réponses de succès sans message (ressources), pour un format unique
 * { message, data, meta? }.
 */
class EnsureResponseMessage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }

        $payload = $response->getData();

        if ($payload instanceof stdClass && property_exists($payload, 'data') && ! property_exists($payload, 'message')) {
            $response->setData((object) ['message' => null, ...(array) $payload]);
        }

        return $response;
    }
}
