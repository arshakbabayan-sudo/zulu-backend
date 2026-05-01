<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferenceController extends Controller
{
    /**
     * GET /api/customer/notification-preferences
     * Returns the user's full preference matrix (event × channel) with
     * default 'true' for any (event, channel) pair that doesn't have an
     * explicit row in the DB.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->get(['event', 'channel', 'enabled'])
            ->keyBy(fn ($r) => $r->event.':'.$r->channel);

        $matrix = [];
        foreach (Notification::EVENT_TYPES as $event) {
            foreach (UserNotificationPreference::CHANNELS as $channel) {
                $key = $event.':'.$channel;
                $matrix[] = [
                    'event' => $event,
                    'channel' => $channel,
                    'enabled' => $rows->has($key) ? (bool) $rows[$key]->enabled : true,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $matrix,
        ]);
    }

    /**
     * PATCH /api/customer/notification-preferences
     * Body: { "preferences": [ { event, channel, enabled }, ... ] }
     * Upserts per (user_id, event, channel) — partial updates supported.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'preferences' => 'required|array|min:1',
            'preferences.*.event' => ['required', 'string', Rule::in(Notification::EVENT_TYPES)],
            'preferences.*.channel' => ['required', 'string', Rule::in(UserNotificationPreference::CHANNELS)],
            'preferences.*.enabled' => 'required|boolean',
        ]);

        foreach ($validated['preferences'] as $pref) {
            UserNotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'event' => $pref['event'],
                    'channel' => $pref['channel'],
                ],
                ['enabled' => (bool) $pref['enabled']],
            );
        }

        return response()->json([
            'success' => true,
            'data' => ['updated' => count($validated['preferences'])],
        ]);
    }
}
