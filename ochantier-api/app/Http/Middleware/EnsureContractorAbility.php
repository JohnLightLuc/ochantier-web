<?php

namespace App\Http\Middleware;

use App\Support\Contractor\ContractorAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Droits d’un collaborateur dans l’application Entrepreneurs et Techniciens : la route appelée doit relever d’un
 * droit de son rôle (ContractorAccess::ROUTES) ; le titulaire du compte a tous les droits.
 */
class EnsureContractorAbility
{
    public function __construct(private ContractorAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $this->access->allows($user, $request->route()?->getName())) {
            return response()->json(['message' => 'Votre rôle ne permet pas cette action.'], 403);
        }

        return $next($request);
    }
}
