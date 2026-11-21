<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes du site public : seul le serveur du site, qui connaît la clé partagée (en-tête X-Landing-Key), peut y
 * déposer les formulaires. Sans clé configurée, les routes sont fermées.
 */
class EnsureLandingKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('services.landing.key');

        abort_if($key === '' || ! hash_equals($key, (string) $request->header('X-Landing-Key')), 403, 'Accès réservé au site O’CHANTIER.');

        return $next($request);
    }
}
