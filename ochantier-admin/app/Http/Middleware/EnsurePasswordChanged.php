<?php

namespace App\Http\Middleware;

use App\Support\Api\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un administrateur au mot de passe provisoire doit le remplacer avant d’accéder aux modules.
 */
class EnsurePasswordChanged
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof AdminUser && $user->mustChangePassword()) {
            return to_route('password.change');
        }

        return $next($request);
    }
}
