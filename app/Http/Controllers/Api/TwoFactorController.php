<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorService $service,
    ) {}

    /** GET /api/account/2fa/status */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['enabled' => $this->service->isEnabled($request->user())],
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

        if (! $this->service->verify($user, $validated['code'])) {
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
        $enabled = $this->service->isEnabled($request->user());

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
