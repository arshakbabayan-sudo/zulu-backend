<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'lang' => ['sometimes', 'string', 'max:5'],
            'source' => ['sometimes', 'string', Rule::in(NewsletterSubscription::SOURCES)],
        ]);

        $email = Str::lower(trim($data['email']));
        $lang = Str::lower($data['lang'] ?? config('app.locale', 'en'));
        $source = $data['source'] ?? NewsletterSubscription::SOURCE_BOTTOM_FORM;

        $record = NewsletterSubscription::query()
            ->where('email', $email)
            ->where('lang', $lang)
            ->first();

        if ($record !== null) {
            if ($record->unsubscribed_at !== null) {
                // Re-subscribe — but require fresh confirmation per GDPR Article 7
                // (consent must be freely given and unambiguous).
                $record->update([
                    'unsubscribed_at' => null,
                    'confirmed_at' => null, // Phase 3.3 — re-confirm
                    'confirmation_token' => bin2hex(random_bytes(32)),
                    'source' => $source,
                    'subscribed_at' => Carbon::now(),
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                ]);

                $this->sendConfirmationMail($record);
            }

            return response()->json([
                'success' => true,
                'message' => 'Subscription pending — please check your email to confirm.',
                'data' => [
                    'id' => $record->id,
                    'requires_confirmation' => $record->confirmed_at === null,
                ],
            ]);
        }

        // Phase 3.3 double opt-in: create a pending subscription with a
        // single-use confirmation token. The receiver must click the
        // emailed link before any newsletter is dispatched.
        $subscription = NewsletterSubscription::query()->create([
            'email' => $email,
            'lang' => $lang,
            'source' => $source,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'subscribed_at' => Carbon::now(),
            'confirmation_token' => bin2hex(random_bytes(32)),
            'confirmed_at' => null,
        ]);

        $this->sendConfirmationMail($subscription);

        return response()->json([
            'success' => true,
            'message' => 'Subscription pending — please check your email to confirm.',
            'data' => [
                'id' => $subscription->id,
                'requires_confirmation' => true,
            ],
        ], 201);
    }

    private function sendConfirmationMail(NewsletterSubscription $subscription): void
    {
        try {
            Mail::to($subscription->email)->queue(new NewsletterConfirmationMail($subscription));
        } catch (\Throwable $e) {
            // Never let a mail-driver glitch break the subscribe endpoint —
            // the row is already persisted and the user can re-trigger by
            // re-submitting the form. Operator gets the failure in logs.
            Log::warning('newsletter.confirmation_mail_failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 3.3 — confirm a pending newsletter subscription via the
     * single-use token sent in the confirmation email.
     *
     * GET /api/newsletter/confirm?token=<token>
     */
    public function confirm(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');
        if ($token === '') {
            return response()->json(['success' => false, 'message' => 'Missing token.'], 400);
        }

        $row = NewsletterSubscription::query()->where('confirmation_token', $token)->first();
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Invalid or already-used token.'], 410);
        }

        if ($row->confirmed_at === null) {
            $row->update([
                'confirmed_at' => Carbon::now(),
                'confirmation_token' => null, // single use
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => ['confirmed' => true],
        ]);
    }
}
