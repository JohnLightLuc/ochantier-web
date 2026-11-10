<?php

use App\Http\Middleware\EnsureActiveCommercial;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePromoterAbility;
use App\Http\Middleware\EnsureResponseMessage;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\RecordPromoterActivity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::middleware('api')->prefix('api/v1/admin')->name('v1.admin.')->group(base_path('routes/admin.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'user.type' => EnsureUserType::class,
            'password.changed' => EnsurePasswordChanged::class,
            'commercial' => EnsureActiveCommercial::class,
            'promoter.can' => EnsurePromoterAbility::class,
            'promoter.activity' => RecordPromoterActivity::class,
            'admin.can' => EnsureAdminPermission::class,
        ]);
        // Le serveur du Back Office relaie l’adresse IP de l’administrateur (X-Forwarded-For) : liste blanche, journal.
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES'))));
        // API sans écran de connexion : un appel non authentifié reçoit une 401 JSON, jamais une redirection.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->throttleApi();
        $middleware->api(append: [EnsureResponseMessage::class]);
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
