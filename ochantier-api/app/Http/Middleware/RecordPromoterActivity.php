<?php

namespace App\Http\Middleware;

use App\Support\Promoter\PromoterActivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inscrit au journal « Activité des utilisateurs » chaque action d’écriture réussie de l’espace promoteur.
 */
class RecordPromoterActivity
{
    public function __construct(private PromoterActivity $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->isSuccessful() && $request->user()) {
            $this->activity->recordRequest($request, $response);
        }

        return $response;
    }
}
