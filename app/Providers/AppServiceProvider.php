<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Invoice;
use App\Observers\BookingObserver;
use App\Observers\InvoiceObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Booking::observe(BookingObserver::class);
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
            $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email={$email}";
        });

    }
}
