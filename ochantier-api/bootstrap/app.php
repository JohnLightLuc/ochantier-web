<?php

use App\Http\Middleware\EnsureActiveCommercial;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'user.type' => EnsureUserType::class,
            'password.changed' => EnsurePasswordChanged::class,
            'commercial' => EnsureActiveCommercial::class,
        ]);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (AuthenticationException $e, Request $request) => response()->json(['message' => 'Session expirée : reconnectez-vous.'], 401));

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => response()->json([
            'message' => 'Trop de tentatives. Réessayez dans quelques instants.',
            'retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
        ], 429, $e->getHeaders()));

        // Ressource absente ou d’un autre compte : sans révéler le modèle recherché.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $e->getPrevious() instanceof ModelNotFoundException || $e->getMessage() === ''
            ? response()->json(['message' => 'Élément introuvable.'], 404)
            : null);

        $exceptions->render(fn (InvalidSignatureException $e, Request $request) => response()->json(['message' => 'Ce lien a expiré. Rechargez la page pour en obtenir un nouveau.'], 403));
    })->create();
