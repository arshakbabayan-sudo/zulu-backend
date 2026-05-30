<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Services\Security\EmailTwoFactorService;
use App\Services\Security\TwoFactorService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private EmailTwoFactorService $emailTwoFactor,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember_me' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Active accounts log in normally. Accounts within their 30-day
        // GDPR deletion window can still log in so they can self-serve a
        // cancel — UserResource flags the pending status to the frontend
        // which surfaces a "scheduled for deletion" banner. Any other
        // status (banned, etc.) is rejected.
        if (! in_array($user->status, [User::STATUS_ACTIVE, User::STATUS_PENDING_DELETION], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // 2FA gate — issue a short-lived challenge token whose only ability is
        // "2fa:challenge" when the account either (a) has TOTP confirmed, or
        // (b) belongs to a role whose `two_factor_required` was set at signup
        // / by the manager from the Permissions drawer. The real session
        // token is minted only after TwoFactorController::verify() succeeds.
        //
        // Method resolution:
        //   - explicit `users.two_factor_method` wins;
        //   - if NULL but TOTP is set up, treat as 'totp' (backwards compat
        //     with users enrolled before the method column existed);
        //   - otherwise default to 'email' (zero-setup channel).
        $totpEnabled = $this->twoFactor->isEnabled($user);
        $requires2fa = $totpEnabled || (bool) $user->two_factor_required;

        if ($requires2fa) {
            $method = $user->two_factor_method;
            if ($method === null) {
                $method = $totpEnabled ? 'totp' : 'email';
            }

            if ($method === 'email') {
                // Generate + email the 6-digit code BEFORE the response so the
                // user typically sees it in their inbox by the time the /2fa
                // page renders. Don't leak a mail failure into the auth flow
                // — log it and surface a friendlier message via the resend
                // button if the user reports they didn't receive one.
                try {
                    $this->emailTwoFactor->generateAndSend($user);
                } catch (\Throwable $e) {
                    Log::warning('Email 2FA send failed', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $challenge = $user->createToken(
                '2fa_challenge',
                ['2fa:challenge'],
                now()->addMinutes(10)
            )->plainTextToken;

            return response()->json([
                'success' => true,
                'data' => [
                    'requires_2fa' => true,
                    'challenge_token' => $challenge,
                    'method' => $method,
                ],
            ]);
        }

        // Remember me: extend token lifetime to 30 days vs default 1 day.
        // Sanctum's third arg to createToken() accepts an explicit expiry
        // and overrides config('sanctum.expiration').
        $rememberMe = (bool) ($validated['remember_me'] ?? false);
        $expiresAt = $rememberMe
            ? now()->addDays(30)
            : now()->addDay();

        $token = $user->createToken('api', ['*'], $expiresAt)->plainTextToken;

        // Phase Զ.15 — record last_login_at so "Active today" stat card
        // + profile "Last seen" row can light up.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => UserResource::make($user->refresh())->toArray($request),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    /** Current Terms / Privacy Policy version stamped on every consent. */
    private const CONSENT_VERSION = 'v1-2026-05';

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                // Complexity: at least one lowercase, one uppercase, one digit
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
            ],
            // Optional — set when the visitor came in via /register/operator or
            // /register/agent. Tells admins what kind of partner this user is
            // becoming BEFORE they submit their CompanyApplication.
            'intended_role' => ['nullable', 'string', \Illuminate\Validation\Rule::in(['operator', 'agent'])],
            // GDPR Article 7 — consent must be explicit. The frontend submits
            // `terms_accepted: true` after the user ticks the checkbox; we
            // record timestamp + IP + policy version so the consent is provable
            // later. Phase 1.2 of remaining-work-2026-05-20 roadmap.
            'terms_accepted' => ['required', 'accepted'],
            // GDPR Article 8 (Child's consent) — services targeted at the
            // general public cannot enrol users under 16 without parental
            // consent. ZULU does not currently process parental-consent flows,
            // so we hard-reject under-16 signups via a self-attestation
            // checkbox. Phase 3.4 of remaining-work-2026-05-20 roadmap.
            'age_confirmed' => ['required', 'accepted'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'status' => User::STATUS_ACTIVE,
            'intended_role' => $validated['intended_role'] ?? null,
            'terms_accepted_at' => now(),
            'consent_ip' => (string) $request->ip(),
            'consent_version' => self::CONSENT_VERSION,
        ]);

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            // Email verification is opt-in; registration should never fail due to mail issues.
        }

        // Welcome mail for partner sign-ups — confirms the account was created and
        // tells the user the next step (complete the company application). The
        // mail contains NO password (user already set their own at /register).
        if (! empty($validated['intended_role'])) {
            try {
                \Illuminate\Support\Facades\Mail::to($user->email)->send(
                    new \App\Mail\PartnerRegistrationWelcomeMail($user)
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Partner welcome mail failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => UserResource::make($user)->toArray($request),
            ],
        ], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'If an account exists for this email, a password reset link has been sent.',
            ],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
            ],
        ]);

        $resetUser = null;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$resetUser) {
                $resetUser = $user;

                $user->forceFill([
                    'password' => $password,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset link. Please request a new password reset.',
            ], 422);
        }

        if ($resetUser) {
            // Revoke ALL tokens so user must re-login everywhere.
            $resetUser->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }
}
