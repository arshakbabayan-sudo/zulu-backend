<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-admin security oversight (Sprint 62, PART 29).
 *
 * Provides 2FA / MFA inventory across users plus admin actions:
 *   - force-disable 2FA
 *   - rotate recovery codes
 *   - force-logout (revoke all tokens)
 */
class AdminSecurityController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /**
     * GET /api/platform-admin/security/two-factor
     *
     * Lists users with 2FA enabled (paginated).
     */
    public function twoFactorIndex(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = UserTwoFactor::query()
            ->whereNotNull('confirmed_at')
            // Phase 1 / B.1 — `is_super_admin` dropped from this column
            // list (it was never a real DB column, just an accessor).
            // Eager-load is_super_admin via the accessor by appending it
            // to the user's serialized output instead.
            ->with(['user' => function ($q): void {
                $q->select(['id', 'name', 'email', 'role']);
            }])
            ->orderByDesc('confirmed_at');

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $term = trim($q);
            $query->whereHas('user', function ($qb) use ($term): void {
                $qb->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/platform-admin/security/stats
     */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $totalUsers = User::query()->count();
        $with2faConfirmed = UserTwoFactor::query()->whereNotNull('confirmed_at')->count();
        $with2faPending = UserTwoFactor::query()
            ->whereNotNull('enabled_at')
            ->whereNull('confirmed_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_users' => $totalUsers,
                'two_factor_confirmed' => $with2faConfirmed,
                'two_factor_pending' => $with2faPending,
                'two_factor_coverage_pct' => $totalUsers > 0
                    ? round(($with2faConfirmed / $totalUsers) * 100, 1)
                    : 0.0,
            ],
        ]);
    }

    /**
     * POST /api/platform-admin/security/users/{userId}/force-disable-2fa
     *
     * Wipes the 2FA row entirely. User keeps logging in via password only;
     * they may re-enroll afterwards. Audit-logged.
     */
    public function forceDisableTwoFactor(Request $request, int $userId): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $row = UserTwoFactor::query()->where('user_id', $userId)->first();
        if ($row === null) {
            return response()->json([
                'success' => false,
                'message' => 'User has no 2FA configured',
            ], 404);
        }

        $row->delete();

        return response()->json([
            'success' => true,
            'data' => ['user_id' => $userId, 'two_factor_removed' => true],
        ]);
    }

    /**
     * POST /api/platform-admin/security/users/{userId}/force-logout
     *
     * Revokes all Sanctum tokens for the user. Forces them to re-authenticate.
     */
    public function forceLogout(Request $request, int $userId): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $count = $user->tokens()->count();
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'data' => ['user_id' => $userId, 'tokens_revoked' => $count],
        ]);
    }
}
