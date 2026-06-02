<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLoyaltyController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private LoyaltyService $service,
    ) {}

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /** GET /api/platform-admin/loyalty/accounts */
    public function indexAccounts(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = LoyaltyAccount::query()->with('user:id,name,email');

        // Loyalty is B2C-global platform data — only super-admins see it.
        // Non-super (operator/agent) get an empty list, never a leak.
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $query->whereRaw('1 = 0');
        }

        if ($tier = $request->query('tier')) {
            $query->where('tier', $tier);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 200);
        $paginated = $query->orderByDesc('lifetime_points')->paginate($perPage);

        return response()->json(['success' => true] + $paginated->toArray());
    }

    /** GET /api/platform-admin/loyalty/accounts/{userId}/transactions */
    public function accountTransactions(Request $request, int $userId): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $account = $this->service->getOrCreateAccount($user);
        $account->loadMissing('transactions');

        return response()->json(['success' => true, 'data' => $account]);
    }

    /** POST /api/platform-admin/loyalty/accounts/{userId}/adjust */
    public function adjust(Request $request, int $userId): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'points' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = User::query()->find($userId);
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $tx = $this->service->adjustManually($user, (int) $validated['points'], $validated['reason']);

        return response()->json(['success' => true, 'data' => $tx]);
    }

    /** GET /api/platform-admin/loyalty/stats */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $byTier = LoyaltyAccount::query()
            ->selectRaw('tier, count(*) as count')
            ->groupBy('tier')
            ->pluck('count', 'tier');

        return response()->json([
            'success' => true,
            'data' => [
                'total_accounts' => LoyaltyAccount::query()->count(),
                'by_tier' => $byTier,
                'total_points_outstanding' => (int) LoyaltyAccount::query()->sum('points_balance'),
                'total_lifetime_points' => (int) LoyaltyAccount::query()->sum('lifetime_points'),
            ],
        ]);
    }
}
