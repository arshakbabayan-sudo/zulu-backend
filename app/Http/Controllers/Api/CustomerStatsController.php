<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing personal statistics (Sprint 71, PART 25).
 *
 * Returns the authenticated user's own order history aggregates,
 * lifetime spend, voucher count, and loyalty progress.
 */
class CustomerStatsController extends Controller
{
    /**
     * GET /api/customer/stats
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $orders = Order::query()->where('user_id', $user->id);

        $paid = (clone $orders)->whereIn('status', ['paid', 'confirmed', 'fulfilled']);
        $totalSpent = (float) (clone $paid)->sum('total');
        $orderCount = (int) (clone $paid)->count();

        $byStatus = (clone $orders)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byCurrency = (clone $paid)
            ->selectRaw('currency, sum(total) as total, count(*) as orders')
            ->groupBy('currency')
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'total' => (float) $r->total,
                'orders' => (int) $r->orders,
            ])
            ->values();

        $cutoff30 = CarbonImmutable::now()->subDays(30);
        $cutoff365 = CarbonImmutable::now()->subDays(365);
        $monthlySpend = (clone $paid)
            ->where('created_at', '>=', $cutoff30)
            ->sum('total');
        $yearlySpend = (clone $paid)
            ->where('created_at', '>=', $cutoff365)
            ->sum('total');

        $loyalty = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $voucherCount = Voucher::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        $firstOrder = (clone $paid)->orderBy('created_at')->value('created_at');
        $lastOrder = (clone $paid)->orderByDesc('created_at')->value('created_at');

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user->id,
                'orders' => [
                    'total' => (int) (clone $orders)->count(),
                    'paid' => $orderCount,
                    'by_status' => $byStatus,
                ],
                'spend' => [
                    'total' => $totalSpent,
                    'avg_order_value' => $orderCount > 0
                        ? round($totalSpent / $orderCount, 2)
                        : 0,
                    'last_30_days' => (float) $monthlySpend,
                    'last_365_days' => (float) $yearlySpend,
                    'by_currency' => $byCurrency,
                ],
                'first_order_at' => $firstOrder,
                'last_order_at' => $lastOrder,
                'vouchers_received' => $voucherCount,
                'loyalty' => $loyalty === null ? null : [
                    'tier' => $loyalty->tier,
                    'points_balance' => (int) $loyalty->points_balance,
                    'lifetime_points' => (int) $loyalty->lifetime_points,
                ],
            ],
        ]);
    }
}
