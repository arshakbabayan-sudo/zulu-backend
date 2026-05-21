<?php

use App\Console\Commands\CheckUiTranslationConsistency;
use App\Console\Commands\ExportUiTranslationsCsv;
use App\Console\Commands\ImportUiTranslationsCsv;
use App\Console\Commands\PruneExpiredTokens;
use App\Console\Commands\PruneOrphanOffers;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ResolveLanguage;
use App\Services\ErrorReporting\ErrorReportService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        CheckUiTranslationConsistency::class,
        ExportUiTranslationsCsv::class,
        ImportUiTranslationsCsv::class,
        PruneExpiredTokens::class,
        PruneOrphanOffers::class,
        \App\Console\Commands\EscalateOverdueCases::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resolve.language' => ResolveLanguage::class,
            'platform-admin' => EnsurePlatformAdmin::class,
        ]);
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/login';
        });
        $middleware->web(append: [
            ResolveLanguage::class,
        ]);
        $middleware->api(append: [
            ResolveLanguage::class,
        ]);

        // Phase 2 (ADR-006) — Sanctum SPA cookie auth foundation.
        // statefulApi() prepends EnsureFrontendRequestsAreStateful so the
        // session cookie issued at /sanctum/csrf-cookie is treated as
        // primary credentials for requests from origins listed in
        // SANCTUM_STATEFUL_DOMAINS. Bearer-token auth (mobile + legacy
        // SPA clients) keeps working through `auth:sanctum` because the
        // guard falls back to the token middleware after the cookie
        // middleware no-ops for non-stateful origins. So this can ship
        // without breaking the current Bearer flow; the frontend cookie
        // migration happens in a separate later commit.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Self-hosted error capture (PART 31, Sprint 67) — write to audit_logs.category=error
        // for any unhandled non-HTTP exception. We skip ValidationException, Auth, NotFound, etc.
        // since those are user-facing 4xx, not platform faults.
        $exceptions->reportable(function (Throwable $e): void {
            // Skip noise: HTTP 4xx, validation, auth.
            if ($e instanceof ValidationException) {
                return;
            }
            if ($e instanceof AuthenticationException) {
                return;
            }
            if ($e instanceof ModelNotFoundException) {
                return;
            }
            if ($e instanceof NotFoundHttpException) {
                return;
            }
            if ($e instanceof MethodNotAllowedHttpException) {
                return;
            }
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return;
            }

            $service = app(ErrorReportService::class);
            $service->captureException($e, request());

            // Sentry — forward the same exception to the cloud error tracker.
            // No-op when SENTRY_LARAVEL_DSN is unset (dev / CI), so this is
            // safe everywhere. Wrapped in try/catch so a transient Sentry
            // outage cannot mask the original error in our own logs.
            if (app()->bound('sentry')) {
                try {
                    \Sentry\Laravel\Integration::captureUnhandledException($e);
                } catch (\Throwable $sentryErr) {
                    // swallow — we already logged via ErrorReportService above
                }
            }
        });

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

        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
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
