<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque l’accès tant que le mot de passe provisoire n’a pas été remplacé.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'Choisissez un nouveau mot de passe pour continuer.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
