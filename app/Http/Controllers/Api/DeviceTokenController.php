<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registers / unregisters the calling user's push device tokens (FCM). The
 * browser/app obtains a token from the Firebase SDK and POSTs it here; on
 * logout or permission revocation it DELETEs it. Tokens are globally unique —
 * re-registering a token re-points it at the current user (handy on shared
 * browsers).
 */
class DeviceTokenController extends Controller
{
    /** POST /api/device-tokens */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['sometimes', 'nullable', 'string', Rule::in(DeviceToken::PLATFORMS)],
        ]);

        $token = DeviceToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? 'web',
                'last_used_at' => now(),
            ],
        );

        return response()->json(['success' => true, 'data' => $token], 201);
    }

    /** DELETE /api/device-tokens */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['success' => true]);
    }
}
