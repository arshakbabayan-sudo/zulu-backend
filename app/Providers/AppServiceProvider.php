<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\User;
use App\Observers\InvoiceObserver;
use App\Services\Admin\AdminAccessService;
use App\Services\Notifications\FirebasePushProvider;
use App\Services\Notifications\NoopPushProvider;
use App\Services\Notifications\NoopSmsProvider;
use App\Services\Notifications\PushProvider;
use App\Services\Notifications\SmsProvider;
use App\Services\Notifications\TwilioSmsProvider;
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

        // Multi-channel notifications — bind interfaces to real providers
        // when credentials are present, otherwise fall back to the Noop
        // implementations so the bulk-send flow still completes.
        $this->app->bind(SmsProvider::class, function () {
            $sid = (string) config('services.twilio.sid', '');
            $token = (string) config('services.twilio.token', '');
            $from = (string) config('services.twilio.from', '');
            if ($sid !== '') {
                return new TwilioSmsProvider($sid, $token, $from);
            }

            return new NoopSmsProvider;
        });

        $this->app->bind(PushProvider::class, function () {
            $serviceAccount = $this->firebaseServiceAccount();
            if ($serviceAccount !== null) {
                $projectId = (string) (config('services.firebase.project_id')
                    ?: ($serviceAccount['project_id'] ?? ''));
                if ($projectId !== '') {
                    return new FirebasePushProvider($projectId, $serviceAccount);
                }
            }

            return new NoopPushProvider;
        });
    }

    /**
     * Decode the configured Firebase service-account JSON — from a file path
     * (services.firebase.credentials, written by deploy) or inline JSON
     * (services.firebase.credentials_json, CI/local). Returns null when neither
     * is present/valid, leaving push on the Noop provider.
     *
     * @return array<string, mixed>|null
     */
    private function firebaseServiceAccount(): ?array
    {
        $json = '';
        $path = (string) config('services.firebase.credentials', '');
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $json = (string) file_get_contents($path);
        }
        if ($json === '') {
            $json = (string) config('services.firebase.credentials_json', '');
        }
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && isset($decoded['client_email'], $decoded['private_key'])
            ? $decoded
            : null;
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

        // Change password (authenticated): 5/min per user
        RateLimiter::for('change-password', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many password-change attempts. Please try again in a minute.',
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

        // Newsletter subscribe form: 5/min per IP, deter automated spam
        RateLimiter::for('newsletter', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many subscription requests. Please try again later.',
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
