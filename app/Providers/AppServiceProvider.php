<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\User;
use App\Observers\InvoiceObserver;
use App\Services\Admin\AdminAccessService;
use App\Services\Packages\Saga\ComponentReserverRegistry;
use App\Services\Packages\Saga\Reservers\AmadeusFlightReserver;
use App\Services\Packages\Saga\Reservers\SupplierApiReserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ComponentReserverRegistry::class, function () {
            $registry = new ComponentReserverRegistry;

            // Flight: prefer dedicated Amadeus reserver when credentials
            // are present; else generic SupplierApiReserver; else stub.
            $amadeus = new AmadeusFlightReserver;
            if ($amadeus->isConfigured()) {
                $registry->register('flight', $amadeus);
            } else {
                $generic = new SupplierApiReserver('flight');
                if ($generic->isConfigured()) {
                    $registry->register('flight', $generic);
                }
            }

            // Hotel / transfer / car / excursion / visa / insurance:
            // swap stub for SupplierApiReserver whenever a credential
            // is present in config/supplier.{type}.*. Untouched types
            // keep their default stub.
            foreach (['hotel', 'transfer', 'car', 'excursion', 'visa', 'insurance'] as $type) {
                $reserver = new SupplierApiReserver($type);
                if ($reserver->isConfigured()) {
                    $registry->register($type, $reserver);
                }
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);

        // API general
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        RateLimiter::for('api_public', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Login: 5/min per IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many login attempts. Please try again in a minute.',
                ], 429);
            });
        });

        // Register: 10/hour per IP
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many registration attempts. Please try again later.',
                ], 429);
            });
        });

        // Password reset: 3/hour per email
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perHour(3)->by(
                $request->input('email', $request->ip())
            )->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many password reset attempts. Please try again later.',
                ], 429);
            });
        });

        // File upload: 20/min per user
        RateLimiter::for('file-upload', function (Request $request) {
            return Limit::perMinute(20)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // Inventory writes (POST/PATCH/DELETE on flights, hotels, transfers, cars, excursions, offers, visas)
        RateLimiter::for('inventory-write', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many inventory write requests. Please slow down.',
                ], 429);
            });
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            // Route admin/operator users to admin.zulu.am, customers to zulu.am.
            $isAdminAudience = false;
            if ($notifiable instanceof User) {
                $adminAccess = app(AdminAccessService::class);
                $isAdminAudience = $adminAccess->isSuperAdmin($notifiable)
                    || $adminAccess->isPlatformAdmin($notifiable)
                    || $adminAccess->isOperatorAdmin($notifiable, null);
            }

            $base = $isAdminAudience
                ? config('app.admin_url')
                : config('app.frontend_url', config('app.url'));
            $base = rtrim((string) $base, '/');

            return "{$base}/reset-password?token={$token}&email={$email}";
        });

    }
}
