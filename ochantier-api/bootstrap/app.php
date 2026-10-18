<?php

use App\Http\Middleware\EnsureActiveCommercial;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

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
    })->create();
