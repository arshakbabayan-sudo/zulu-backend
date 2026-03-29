<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'data' => [
                    'message' => 'User not found.',
                ],
            ], 404);
        }

        if (! hash_equals(sha1($user->email), $hash)) {
            return response()->json([
                'success' => false,
                'data' => [
                    'message' => 'Invalid verification hash.',
                ],
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Already verified.',
                ],
            ]);
        }

        $user->markEmailAsVerified();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Email verified successfully.',
            ],
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user?->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Already verified.',
                ],
            ]);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            // Verification email failure must NOT break resend.
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Verification email sent.',
            ],
        ]);
    }
}

