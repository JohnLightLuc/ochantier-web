<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route du Back Office aux administrateurs dont un rôle accorde l’une des permissions demandées
 * (admin.can:workers.validate ou admin.can:payments.approve,finance.approve).
 */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! array_filter($permissions, fn (string $code) => $admin->hasPermission($code))) {
            return response()->json([
                'message' => 'Votre rôle ne permet pas cette action.',
                'code' => 'permission_denied',
            ], 403);
        }

        return $next($request);
    }
}
