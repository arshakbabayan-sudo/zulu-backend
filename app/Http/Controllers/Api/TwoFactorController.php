<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Services\Security\EmailTwoFactorService;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorService $service,
        private EmailTwoFactorService $emailService,
    ) {}

    /**
     * Resolve the active 2FA channel for a user. Mirrors AuthController::login:
     * explicit method wins; if NULL but TOTP is set up, treat as 'totp';
     * otherwise default to 'email' (zero-setup channel for staff).
     */
    private function methodFor(\App\Models\User $user): string
    {
        $method = $user->two_factor_method;
        if ($method !== null) {
            return $method;
        }

        return $this->service->isEnabled($user) ? 'totp' : 'email';
    }

    /** GET /api/account/2fa/status */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $this->service->isEnabled($user),
                // 2FA hierarchy (2026-05-31) — `method` is the user's pick;
                // `required` is the manager-controlled enforcement flag.
                'method' => $user->two_factor_method ?? ($this->service->isEnabled($user) ? 'totp' : 'email'),
                'required' => (bool) $user->two_factor_required,
            ],
        ]);
    }

    /** POST /api/account/2fa/setup */
    public function setup(Request $request): JsonResponse
    {
        try {
            $payload = $this->service->setup($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $payload], 201);
    }

    /** POST /api/account/2fa/confirm */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $ok = $this->service->confirm($request->user(), $validated['code']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (! $ok) {
            return response()->json(['success' => false, 'message' => 'Invalid code'], 422);
        }

        return response()->json(['success' => true, 'data' => ['enabled' => true]]);
    }

    /**
     * POST /api/account/2fa/verify — login challenge exchange.
     *
     * Called with the short-lived CHALLENGE token (ability "2fa:challenge")
     * issued by AuthController::login() when the account has 2FA on. On a
     * valid code we consume the challenge token and mint a real session
     * token, returning it together with the user — exactly the shape the
     * normal login endpoint returns, so the frontend can store it and proceed.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Only a dedicated challenge token may be exchanged for a full
        // session. A challenge token has the "2fa:challenge" ability and,
        // crucially, NOT the "*" wildcard that real session tokens carry.
        $isChallenge = $token !== null
            && $token->can('2fa:challenge')
            && ! $token->can('*');

        if (! $isChallenge) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired 2FA challenge session. Please log in again.',
            ], 403);
        }

        // Dispatch by the user's chosen channel — TOTP uses the persistent
        // secret, email uses the freshly-stored one-time code-hash from
        // /api/login's email_two_factor->generateAndSend() call.
        $method = $this->methodFor($user);
        $ok = $method === 'email'
            ? $this->emailService->verify($user, $validated['code'])
            : $this->service->verify($user, $validated['code']);

        if (! $ok) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid code',
            ], 422);
        }

        // Consume the one-time challenge token, then mint the real session.
        $token->delete();
        $expiresAt = now()->addDay();
        $sessionToken = $user->createToken('api', ['*'], $expiresAt)->plainTextToken;
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $sessionToken,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => UserResource::make($user->refresh())->toArray($request),
            ],
        ]);
    }

    /** POST /api/account/2fa/disable */
    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $ok = $this->service->disable($request->user(), $validated['password']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => $ok, 'data' => ['disabled' => $ok]]);
    }

    /**
     * POST /api/account/2fa/resend
     *
     * Frontend-facing endpoint for the "Resend code" affordance on the 2FA
     * verification page. Backend uses TOTP (RFC 6238) which generates a new
     * code client-side every 30 seconds — there is nothing to "send" from
     * the server. This endpoint returns 200 with a friendly notice so the
     * frontend can give the user feedback without erroring.
     *
     * If/when email-OTP is added as a fallback, the email-send logic lands
     * here and the response shape stays the same.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        $method = $this->methodFor($user);

        if ($method === 'email') {
            try {
                $this->emailService->generateAndSend($user);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'method' => 'email',
                        'message' => 'A fresh code is on its way to your email.',
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Email 2FA resend failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Could not send a new code right now. Try again in a moment.',
                ], 502);
            }
        }

        // TOTP: nothing to send — the code is generated client-side.
        $enabled = $this->service->isEnabled($user);

        return response()->json([
            'success' => true,
            'data' => [
                'method' => 'totp',
                'message' => $enabled
                    ? 'Open your authenticator app for the latest code (refreshes every 30s).'
                    : '2FA is not enabled on this account.',
            ],
        ]);
    }

    /**
     * PUT /api/account/2fa/method — user-chosen 2FA channel.
     *
     * Switching to 'totp' alone does NOT enrol — the user must still go
     * through setup() → confirm(). Switching to 'email' is immediate; the
     * next login generates and sends a code automatically.
     */
    public function setMethod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string', 'in:totp,email'],
        ]);

        $user = $request->user();
        $user->forceFill(['two_factor_method' => $validated['method']])->saveQuietly();

        return response()->json([
            'success' => true,
            'data' => [
                'two_factor_method' => $validated['method'],
            ],
        ]);
    }

    /** POST /api/account/2fa/recovery-codes/regenerate */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        try {
            $codes = $this->service->regenerateRecoveryCodes($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['recovery_codes' => $codes],
        ]);
    }

    /**
     * GET /api/account/2fa/recovery-codes
     *
     * Returns metadata only (count, last_generated_at) — never the codes
     * themselves. Codes are stored hashed and cannot be retrieved after
     * the initial setup() / regenerate() response. Use this to display
     * "You have N recovery codes generated on D" in the security UI.
     */
    public function recoveryCodesStatus(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getRecoveryCodesMeta($request->user()),
        ]);
    }
}
