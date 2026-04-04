<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminRolloutTelemetryController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    /**
     * Bearer-authenticated screen views from zulu-admin-next (R1 shadow observability).
     */
    public function screenView(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'screen' => ['required', 'string', 'max:512', 'regex:/^\/[A-Za-z0-9_\-\/]*$/'],
        ]);

        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $isSuper = $this->adminAccessService->isSuperAdmin($user);
        $role = $this->adminAccessService->canonicalRoleForUser($user);

        Log::info('admin_next_screen_view', [
            'user_id' => $user->id,
            'role' => $role,
            'is_statistics_elevated_only' => $this->adminAccessService->isStatisticsElevatedOnly($user),
            'screen' => $validated['screen'],
            'recorded_at' => now()->toIso8601String(),
        ]);

        return response()->json(['success' => true]);
    }
}
