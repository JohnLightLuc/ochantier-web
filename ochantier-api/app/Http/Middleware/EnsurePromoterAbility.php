<?php

namespace App\Http\Middleware;

use App\Support\Promoter\PromoterAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route de l’espace promoteur aux rôles qui ont le droit demandé (PromoterAccess::ABILITIES).
 */
class EnsurePromoterAbility
{
    public function __construct(private PromoterAccess $access) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        if (! $this->access->profile($user)) {
            return response()->json(['message' => 'Votre accès à ce compte a été retiré. Contactez son propriétaire.'], 403);
        }

        if (! $this->access->can($user, $ability)) {
            return response()->json(['message' => 'Votre rôle ne permet pas cette action.'], 403);
        }

        return $next($request);
    }
}
