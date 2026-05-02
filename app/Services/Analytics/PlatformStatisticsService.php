<?php

namespace App\Services\Analytics;

use App\Models\Company;
use App\Models\Connection;
use App\Models\Contract;
use App\Models\InsurancePolicy;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\PackageBookingSaga;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PART 25 — Platform-wide executive statistics for admin dashboard.
 * Pulls data from every shipped feature (orders, vouchers, contracts,
 * connections, package sagas, insurance, loyalty).
 */
class PlatformStatisticsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboardSnapshot(int $days = 30): array
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        return [
            'window_days' => $days,
            'window_start' => $cutoff->toIso8601String(),
            'orders' => $this->orderStats($cutoff),
            'revenue' => $this->revenueStats($cutoff),
            'users' => $this->userStats($cutoff),
            'sellers' => $this->sellerStats(),
            'vouchers' => $this->voucherStats($cutoff),
            'contracts' => $this->contractStats(),
            'connections' => $this->connectionStats(),
            'package_sagas' => $this->sagaStats($cutoff),
            'insurance' => $this->insuranceStats($cutoff),
            'loyalty' => $this->loyaltyStats(),
            'top_sellers' => $this->topSellersByRevenue(10, $cutoff),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderStats(CarbonImmutable $cutoff): array
    {
        $byStatus = Order::query()
            ->selectRaw('status, count(*) as count')
            ->where('created_at', '>=', $cutoff)
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'total_in_window' => Order::query()->where('created_at', '>=', $cutoff)->count(),
            'by_status' => $byStatus,
            'open_carts' => Order::query()->where('status', 'cart')->count(),
            'paid' => Order::query()->where('status', 'paid')->count(),
            'confirmed' => Order::query()->where('status', 'confirmed')->count(),
            'failed' => Order::query()->where('status', 'failed')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenueStats(CarbonImmutable $cutoff): array
    {
        $paidOrders = Order::query()
            ->whereIn('status', ['paid', 'confirmed'])
            ->where('created_at', '>=', $cutoff);

        return [
            'total' => (float) $paidOrders->clone()->sum('total'),
            'order_count' => (int) $paidOrders->clone()->count(),
            'avg_order_value' => (float) $paidOrders->clone()->avg('total') ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userStats(CarbonImmutable $cutoff): array
    {
        return [
            'total' => User::query()->count(),
            'new_in_window' => User::query()->where('created_at', '>=', $cutoff)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sellerStats(): array
    {
        return [
            'total' => Company::query()->where('status', 'active')->count(),
            'by_type' => Company::query()
                ->selectRaw('type, count(*) as count')
                ->where('status', 'active')
                ->groupBy('type')
                ->pluck('count', 'type'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function voucherStats(CarbonImmutable $cutoff): array
    {
        return [
            'total' => Voucher::query()->count(),
            'issued_in_window' => Voucher::query()->where('created_at', '>=', $cutoff)->count(),
            'by_status' => Voucher::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contractStats(): array
    {
        return [
            'total' => Contract::query()->count(),
            'by_status' => Contract::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionStats(): array
    {
        return [
            'total' => Connection::query()->count(),
            'active' => Connection::query()->where('status', 'active')->count(),
            'pending' => Connection::query()->where('status', 'proposed')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sagaStats(CarbonImmutable $cutoff): array
    {
        $window = PackageBookingSaga::query()->where('created_at', '>=', $cutoff);

        $total = (int) $window->clone()->count();
        $confirmed = (int) $window->clone()->where('status', 'confirmed')->count();

        return [
            'total_in_window' => $total,
            'confirmed' => $confirmed,
            'failed' => (int) $window->clone()->whereIn('status', ['failed', 'rolled_back'])->count(),
            'success_rate' => $total > 0 ? round(($confirmed / $total) * 100, 2) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function insuranceStats(CarbonImmutable $cutoff): array
    {
        return [
            'active_policies' => InsurancePolicy::query()->where('status', 'active')->count(),
            'issued_in_window' => InsurancePolicy::query()->where('issued_at', '>=', $cutoff)->count(),
            'total_premium_collected' => (float) InsurancePolicy::query()
                ->where('issued_at', '>=', $cutoff)
                ->sum('premium_paid'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loyaltyStats(): array
    {
        return [
            'total_accounts' => LoyaltyAccount::query()->count(),
            'by_tier' => LoyaltyAccount::query()
                ->selectRaw('tier, count(*) as count')
                ->groupBy('tier')
                ->pluck('count', 'tier'),
            'points_outstanding' => (int) LoyaltyAccount::query()->sum('points_balance'),
        ];
    }

    /**
     * @return array<int, array{company_id: int, name: ?string, revenue: float, orders: int}>
     */
    private function topSellersByRevenue(int $limit, CarbonImmutable $cutoff): array
    {
        $rows = Order::query()
            ->select([
                'company_id',
                DB::raw('count(*) as orders'),
                DB::raw('sum(total) as revenue'),
            ])
            ->whereIn('status', ['paid', 'confirmed'])
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $companyIds = $rows->pluck('company_id')->all();
        $names = Company::query()->whereIn('id', $companyIds)->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'company_id' => (int) $r->company_id,
            'name' => $names[$r->company_id] ?? null,
            'revenue' => (float) $r->revenue,
            'orders' => (int) $r->orders,
        ])->all();
    }
}
