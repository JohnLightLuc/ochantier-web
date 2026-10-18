<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve un groupe de routes à un type d’utilisateur (promoter, commercial, worker).
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! in_array($user->user_type->value, $types, true)) {
            return response()->json(['message' => 'Cette fonctionnalité n’est pas disponible pour votre application.'], 403);
        }

        return $next($request);
    }
}
