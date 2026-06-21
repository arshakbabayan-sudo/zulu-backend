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

        // Phase 1 §1 — revenue partitioned by currency. The 'total' scalar (and
        // avg_order_value) sum ACROSS currencies; 'total_by_currency' groups by
        // orders.currency. Original scalars kept for back-compat.
        $totalByCurrency = $paidOrders->clone()
            ->selectRaw('currency, COALESCE(SUM(total), 0) AS amount')
            ->groupBy('currency')
            ->pluck('amount', 'currency')
            ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => round((float) $v, 2)])
            ->toArray();

        return [
            'total' => (float) $paidOrders->clone()->sum('total'),
            'total_by_currency' => $totalByCurrency,
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
            'total' => Company::query()->where('governance_status', 'active')->count(),
            'by_type' => Company::query()
                ->selectRaw('type, count(*) as count')
                ->where('governance_status', 'active')
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
        // Phase 1 §1 — premiums partitioned by currency (insurance_policies has
        // a `currency` column). The scalar 'total_premium_collected' sums ACROSS
        // currencies; the sibling groups by currency. Scalar kept.
        $premiumByCurrency = InsurancePolicy::query()
            ->where('issued_at', '>=', $cutoff)
            ->selectRaw('currency, COALESCE(SUM(premium_paid), 0) AS amount')
            ->groupBy('currency')
            ->pluck('amount', 'currency')
            ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => round((float) $v, 2)])
            ->toArray();

        return [
            'active_policies' => InsurancePolicy::query()->where('status', 'active')->count(),
            'issued_in_window' => InsurancePolicy::query()->where('issued_at', '>=', $cutoff)->count(),
            'total_premium_collected' => (float) InsurancePolicy::query()
                ->where('issued_at', '>=', $cutoff)
                ->sum('premium_paid'),
            'total_premium_collected_by_currency' => $premiumByCurrency,
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
     * Revenue + order count per day over the last N days.
     * Returns oldest-first list, with zero rows for days that had no paid orders.
     *
     * @return list<array{date: string, revenue: float, orders: int}>
     */
    public function revenueTimeSeries(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $cutoff = CarbonImmutable::now()->startOfDay()->subDays($days - 1);

        $rows = Order::query()
            ->selectRaw("date_trunc('day', created_at) as bucket, count(*) as orders, sum(total) as revenue")
            ->whereIn('status', ['paid', 'confirmed'])
            ->where('created_at', '>=', $cutoff)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->bucket)->toDateString());

        // Phase 1 §1 — per-day revenue partitioned by currency. The scalar
        // 'revenue' per day sums ACROSS currencies; 'revenue_by_currency' groups
        // by day AND orders.currency. Scalar kept.
        $byDateCurrency = [];
        $currencyRows = Order::query()
            ->selectRaw("date_trunc('day', created_at) as bucket, currency, sum(total) as revenue")
            ->whereIn('status', ['paid', 'confirmed'])
            ->where('created_at', '>=', $cutoff)
            ->groupBy('bucket', 'currency')
            ->get();
        foreach ($currencyRows as $r) {
            $date = CarbonImmutable::parse($r->bucket)->toDateString();
            $code = strtoupper((string) $r->currency);
            if ($code === '') {
                continue;
            }
            $byDateCurrency[$date][$code] = round((float) $r->revenue, 2);
        }

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $cutoff->addDays($i)->toDateString();
            $row = $rows->get($date);
            $series[] = [
                'date' => $date,
                'revenue' => $row ? (float) $row->revenue : 0.0,
                'revenue_by_currency' => $byDateCurrency[$date] ?? [],
                'orders' => $row ? (int) $row->orders : 0,
            ];
        }

        return $series;
    }

    /**
     * Order count per day per status, oldest-first.
     *
     * @return list<array{date: string, total: int, by_status: array<string,int>}>
     */
    public function ordersTimeSeries(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $cutoff = CarbonImmutable::now()->startOfDay()->subDays($days - 1);

        $rows = Order::query()
            ->selectRaw("date_trunc('day', created_at) as bucket, status, count(*) as count")
            ->where('created_at', '>=', $cutoff)
            ->groupBy('bucket', 'status')
            ->orderBy('bucket')
            ->get();

        $byDate = [];
        foreach ($rows as $r) {
            $date = CarbonImmutable::parse($r->bucket)->toDateString();
            $byDate[$date][$r->status] = (int) $r->count;
        }

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $cutoff->addDays($i)->toDateString();
            $statusMap = $byDate[$date] ?? [];
            $series[] = [
                'date' => $date,
                'total' => array_sum($statusMap),
                'by_status' => $statusMap,
            ];
        }

        return $series;
    }

    /**
     * Per-seller drill-down: revenue, order count, top services, voucher count.
     *
     * @return array<string, mixed>
     */
    public function perSellerStats(int $companyId, int $days = 30): array
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        $orders = Order::query()
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $cutoff);

        $paid = (clone $orders)->whereIn('status', ['paid', 'confirmed']);

        // Phase 1 §1 — seller revenue partitioned by currency. The 'total_revenue'
        // scalar (and avg_order_value) sum ACROSS currencies; the sibling groups
        // by orders.currency. Scalars kept.
        $totalRevenueByCurrency = (clone $paid)
            ->selectRaw('currency, COALESCE(SUM(total), 0) AS amount')
            ->groupBy('currency')
            ->pluck('amount', 'currency')
            ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => round((float) $v, 2)])
            ->toArray();

        return [
            'company_id' => $companyId,
            'window_days' => $days,
            'total_orders' => (int) (clone $orders)->count(),
            'paid_orders' => (int) (clone $paid)->count(),
            'total_revenue' => (float) (clone $paid)->sum('total'),
            'total_revenue_by_currency' => $totalRevenueByCurrency,
            'avg_order_value' => (float) ((clone $paid)->avg('total') ?? 0),
            'orders_by_status' => (clone $orders)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'vouchers_issued' => Voucher::query()
                ->where('issuer_company_id', $companyId)
                ->where('created_at', '>=', $cutoff)
                ->count(),
        ];
    }

    /**
     * Public top-seller list (used by both admin dashboard + per-seller drill-down).
     *
     * @return list<array{company_id: int, name: ?string, revenue: float, orders: int}>
     */
    public function topSellers(int $limit = 10, int $days = 30): array
    {
        return $this->topSellersByRevenue($limit, CarbonImmutable::now()->subDays($days));
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

        // Phase 1 §1 — per-seller revenue partitioned by currency. The scalar
        // 'revenue' (also the ranking key, intentionally unchanged) sums ACROSS
        // currencies; 'revenue_by_currency' groups each seller's revenue by
        // orders.currency. Ranking + scalar kept for back-compat.
        $revenueByCurrency = [];
        if (count($companyIds) > 0) {
            $currencyRows = Order::query()
                ->select([
                    'company_id',
                    'currency',
                    DB::raw('COALESCE(SUM(total), 0) as revenue'),
                ])
                ->whereIn('status', ['paid', 'confirmed'])
                ->where('created_at', '>=', $cutoff)
                ->whereIn('company_id', $companyIds)
                ->groupBy('company_id', 'currency')
                ->get();
            foreach ($currencyRows as $r) {
                $code = strtoupper((string) $r->currency);
                if ($code === '') {
                    continue;
                }
                $revenueByCurrency[(int) $r->company_id][$code] = round((float) $r->revenue, 2);
            }
        }

        return $rows->map(fn ($r) => [
            'company_id' => (int) $r->company_id,
            'name' => $names[$r->company_id] ?? null,
            'revenue' => (float) $r->revenue,
            'revenue_by_currency' => $revenueByCurrency[(int) $r->company_id] ?? [],
            'orders' => (int) $r->orders,
        ])->all();
    }
}
