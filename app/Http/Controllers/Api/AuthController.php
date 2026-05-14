<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
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

        // Remember me: extend token lifetime to 30 days vs default 1 day.
        // Sanctum's third arg to createToken() accepts an explicit expiry
        // and overrides config('sanctum.expiration').
        $rememberMe = (bool) ($validated['remember_me'] ?? false);
        $expiresAt = $rememberMe
            ? now()->addDays(30)
            : now()->addDay();

        $token = $user->createToken('api', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => UserResource::make($user)->toArray($request),
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

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'status' => User::STATUS_ACTIVE,
        ]);

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            // Email verification is opt-in; registration should never fail due to mail issues.
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
