<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bookings group v2 — stats endpoints powering the 4-stat-card row on
 * /platform/bookings and /platform/package-orders.
 *
 * Both endpoints:
 *  - platform-admin gated (super_admin or canonical platform_admin)
 *  - 60s server-side cache
 *  - defensive Schema checks → safe zeroes on missing columns
 *
 * Companion to docs/admin_designe/Bookings/bookings_implementation_prompt.md.
 */
class BookingsStatsController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $adminAccessService,
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
     * Phase 5 — restrict an orders query to the caller's visible companies
     * (seller via company_id OR referring agent via agent_company_id). null =
     * super-admin = no filter; [] = caller sees nothing → zero rows.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  ?list<int>  $scopeIds
     */
    private function applyOrderScope($query, ?array $scopeIds): void
    {
        if ($scopeIds === null) {
            return;
        }
        if (count($scopeIds) === 0) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function ($q) use ($scopeIds): void {
            $q->whereIn('company_id', $scopeIds)->orWhereIn('agent_company_id', $scopeIds);
        });
    }

    /**
     * GET /platform-admin/bookings/stats?range=30d
     *
     * Powers the 4 stat cards on /platform/bookings.
     * Reads the `orders` table (excluding `metadata->legacy_origin = package_order`
     * which is owned by Package orders page).
     */
    public function bookings(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $range = (string) $request->query('range', '30d');
        $rangeStart = match ($range) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        // Phase 5 — scope totals to the caller's visible companies (super =
        // all). The cache key carries the scope suffix so a scoped staffer
        // never reads the global super-admin entry.
        [$scopeIds, $scopeSuffix] = $this->adminAccessService->statsScope($request->user());

        $data = Cache::remember("bookings_stats_{$range}_{$scopeSuffix}", 60, function () use ($rangeStart, $scopeIds): array {
            if (! Schema::hasTable('orders')) {
                return [
                    'total_count' => 0,
                    'pending_count' => 0,
                    'confirmed_count' => 0,
                    'revenue_amount' => 0,
                ];
            }

            // "All bookings" = orders that are NOT package orders.
            // The split is the same one used by PlatformAdminService::listAllPackageOrders.
            $base = DB::table('orders')
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereNull('metadata')
                        ->orWhereRaw("(metadata->>'legacy_origin') IS DISTINCT FROM 'package_order'");
                });
            $this->applyOrderScope($base, $scopeIds);

            $totalCount = (int) (clone $base)
                ->where('created_at', '>=', $rangeStart)
                ->count();

            $pendingCount = (int) (clone $base)
                ->whereIn('status', ['cart', 'pending_payment'])
                ->count();

            $confirmedCount = (int) (clone $base)
                ->whereIn('status', ['paid', 'confirmed'])
                ->where('created_at', '>=', $rangeStart)
                ->count();

            $revenueAmount = (float) (clone $base)
                ->whereIn('status', ['paid', 'confirmed'])
                ->where('created_at', '>=', $rangeStart)
                ->sum('total');

            return [
                'total_count' => $totalCount,
                'pending_count' => $pendingCount,
                'confirmed_count' => $confirmedCount,
                'revenue_amount' => round($revenueAmount, 2),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/package-orders/stats?range=30d
     *
     * Powers the 4 stat cards on /platform/package-orders.
     * Reads the `orders` table where metadata->legacy_origin = 'package_order'.
     */
    public function packageOrders(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $range = (string) $request->query('range', '30d');
        $rangeStart = match ($range) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        [$scopeIds, $scopeSuffix] = $this->adminAccessService->statsScope($request->user());

        $data = Cache::remember("package_orders_stats_{$range}_{$scopeSuffix}", 60, function () use ($rangeStart, $scopeIds): array {
            // Newer model uses Order table with metadata flag.
            // Legacy table `package_orders` still exists but is no longer the
            // canonical store (see PlatformAdminService::listAllPackageOrders).
            if (! Schema::hasTable('orders')) {
                return [
                    'total_count' => 0,
                    'paid_count' => 0,
                    'pending_count' => 0,
                    'total_value' => 0,
                ];
            }

            $base = DB::table('orders')
                ->whereNull('deleted_at')
                ->whereRaw("(metadata->>'legacy_origin') = 'package_order'");
            $this->applyOrderScope($base, $scopeIds);

            $totalCount = (int) (clone $base)
                ->where('created_at', '>=', $rangeStart)
                ->count();

            $paidCount = (int) (clone $base)
                ->whereIn('status', ['paid', 'confirmed'])
                ->count();

            $pendingCount = (int) (clone $base)
                ->whereIn('status', ['cart', 'pending_payment'])
                ->count();

            $totalValue = (float) (clone $base)
                ->whereIn('status', ['paid', 'confirmed'])
                ->where('created_at', '>=', $rangeStart)
                ->sum('total');

            return [
                'total_count' => $totalCount,
                'paid_count' => $paidCount,
                'pending_count' => $pendingCount,
                'total_value' => round($totalValue, 2),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}
