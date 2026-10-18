<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Jetons d’accès JWT
    |--------------------------------------------------------------------------
    |
    | Jeton d’accès court signé (HS256) portant l’identifiant de session (sid).
    | Chaque session vit dans auth_sessions : le jeton n’est accepté que si sa
    | session est active et s’il est le dernier émis pour elle. Le jeton de
    | rafraîchissement est opaque, à usage unique et renouvelé à chaque emploi.
    |
    */

    'secret' => env('JWT_SECRET'),

    'algorithm' => 'HS256',

    'issuer' => env('JWT_ISSUER', env('APP_URL')),

    // Durée de vie du jeton d’accès, en minutes.
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 15),

    // Durée de vie d’une session sans rafraîchissement, en minutes (30 jours).
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 43200),

    // Tolérance d’horloge entre serveurs, en secondes.
    'leeway' => 30,

];
