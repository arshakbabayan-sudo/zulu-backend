<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
        ],
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\BackfillProductLocations::class,
        \App\Console\Commands\CheckUiTranslationConsistency::class,
        \App\Console\Commands\ExportUiTranslationsCsv::class,
        \App\Console\Commands\ImportUiTranslationsCsv::class,
        \App\Console\Commands\PruneExpiredTokens::class,
        \App\Console\Commands\PruneOrphanOffers::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resolve.language' => \App\Http\Middleware\ResolveLanguage::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\ResolveLanguage::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\ResolveLanguage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not found',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not found',
                ], 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Method not allowed',
                ], 405);
            }
        });

        $exceptions->render(function (\Throwable $e, $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            $debug = config('app.debug', false);

            return response()->json([
                'success' => false,
                'message' => $debug ? $e->getMessage() : 'Server error',
                'exception' => $debug ? get_class($e) : null,
            ], 500);
        });
    })->create();
