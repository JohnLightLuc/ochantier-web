<?php

namespace App\Http\Middleware;

use App\Enums\CommercialProfileStatus;
use App\Models\CommercialProfile;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve l’espace commercial aux commerciaux dont le contrat est actif ; le profil est ensuite accessible
 * par EnsureActiveCommercial::profile($request).
 */
class EnsureActiveCommercial
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $profile = $user instanceof User ? $user->commercialProfile : null;

        if (! $profile) {
            return response()->json(['message' => 'Cette fonctionnalité n’est pas disponible pour votre application.'], 403);
        }

        if ($profile->status !== CommercialProfileStatus::Actif) {
            return response()->json(['message' => 'Votre accès commercial n’est plus actif. Contactez votre responsable O’CHANTIER.'], 403);
        }

        $request->attributes->set('commercial', $profile);

        return $next($request);
    }

    public static function profile(Request $request): CommercialProfile
    {
        return $request->attributes->get('commercial');
    }
}
