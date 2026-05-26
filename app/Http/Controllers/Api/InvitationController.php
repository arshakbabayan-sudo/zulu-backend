<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bucket D Phase D.1 — public-facing invitation flow.
 *
 * verify(): pre-flight check before showing the password-set form on the
 * frontend landing page. Returns user/company/role info if token is valid +
 * still pending; returns 410/422 otherwise.
 *
 * accept(): the invitee posts a new password here. Marks invitation accepted,
 * sets the user's password, activates the user. Tokens are single-use.
 */
class InvitationController extends Controller
{
    public function verify(string $token): JsonResponse
    {
        $invitation = UserInvitation::query()
            ->with(['user:id,name,email,status', 'company:id,name', 'role:id,name'])
            ->where('token', $token)
            ->first();

        if ($invitation === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation not found',
                'reason' => 'not_found',
            ], 404);
        }

        if ($invitation->accepted_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation has already been accepted.',
                'reason' => 'already_accepted',
            ], 410);
        }

        if ($invitation->revoked_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation has been revoked.',
                'reason' => 'revoked',
            ], 410);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation has expired.',
                'reason' => 'expired',
            ], 410);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $invitation->user->name,
                    'email' => $invitation->user->email,
                ],
                'company' => [
                    'name' => $invitation->company->name,
                ],
                'role' => [
                    'name' => $invitation->role->name,
                ],
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $invitation = UserInvitation::query()->where('token', $token)->first();

        if ($invitation === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation not found',
                'reason' => 'not_found',
            ], 404);
        }

        if ($invitation->accepted_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation has already been accepted.',
                'reason' => 'already_accepted',
            ], 410);
        }

        if ($invitation->revoked_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation has been revoked.',
                'reason' => 'revoked',
            ], 410);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation has expired.',
                'reason' => 'expired',
            ], 410);
        }

        DB::transaction(function () use ($invitation, $validated): void {
            // Hash cast on User::password handles bcrypt encoding.
            $invitation->user->update([
                'password' => $validated['password'],
                'status' => User::STATUS_ACTIVE,
            ]);
            $invitation->update(['accepted_at' => now()]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Invitation accepted. You can now sign in.',
            'data' => [
                'email' => $invitation->user->email,
            ],
        ]);
    }
}
