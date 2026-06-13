<?php

namespace App\Services\Analytics;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * order_item type => [destination table, FK column → locations.id].
     * Canonical destination resolution: the legacy string city columns were
     * dropped 2026-04-16 (ADR004); destinations come from location_id → locations.name.
     */
    private const DESTINATION_SOURCES = [
        'hotel' => ['hotels', 'location_id'],
        'flight' => ['flights', 'arrival_location_id'],
        'transfer' => ['transfers', 'destination_location_id'],
        'car' => ['cars', 'location_id'],
        'excursion' => ['excursions', 'location_id'],
        'package' => ['packages', 'destination_location_id'],
        'visa' => ['visas', 'location_id'],
    ];

    /**
     * Single assembly path for operator (company) statistics — used by the API
     * (operator's own dashboard) and the super-admin per-company drill-down.
     *
     * @return array<string, mixed>
     */
    public function buildOperatorCompanyStatisticsPayload(int $companyId, Request $request): array
    {
        $filters = [
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'service_type' => $request->query('service_type'),
        ];

        $stats = $this->getCompanyStats($companyId, $filters);
        $primaryCurrency = $this->primaryCurrency($stats['revenue_by_currency']);

        $trend = $this->getSalesTrend(
            $companyId,
            (string) $request->query('period', 'monthly'),
            $primaryCurrency
        );

        $activeOffers = Offer::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [Offer::STATUS_ACTIVE, Offer::STATUS_PUBLISHED])
            ->count();

        return [
            'stats' => [
                ...$stats,
                'active_offers' => $activeOffers,
                'primary_currency' => $primaryCurrency,
            ],
            'trend' => $trend,
            'service_breakdown' => $this->serviceBreakdown($companyId, $filters, $primaryCurrency),
            'top_destinations' => $this->topDestinations($companyId, $filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getCompanyStats(int $companyId, array $filters = []): array
    {
        $paidInvoices = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereHas('order', function (Builder $query) use ($companyId): void {
                $query->where('company_id', $companyId);
            });

        $orders = Order::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['paid', 'confirmed']);

        $this->applyDateFilters($paidInvoices, $filters);
        $this->applyDateFilters($orders, $filters);

        if (! empty($filters['service_type']) && is_string($filters['service_type'])) {
            $serviceType = $filters['service_type'];

            $paidInvoices->where('invoice_type', $serviceType);
            $orders->whereHas('items', function (Builder $query) use ($serviceType): void {
                $query->where('item_type', $serviceType);
            });
        }

        $bookingsByType = (clone $paidInvoices)
            ->select('invoice_type', DB::raw('COUNT(*) as total'))
            ->groupBy('invoice_type')
            ->pluck('total', 'invoice_type')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        // Revenue/commission are NEVER summed across currencies (ADR/convention):
        // return a currency => amount map and let the UI render per currency.
        $revenueByCurrency = (clone $paidInvoices)
            ->selectRaw("COALESCE(currency, 'USD') as cur, COALESCE(SUM(client_price), 0) as amount")
            ->groupByRaw("COALESCE(currency, 'USD')")
            ->pluck('amount', 'cur')
            ->map(fn ($v) => round((float) $v, 2))
            ->toArray();

        $commissionByCurrency = (clone $paidInvoices)
            ->selectRaw("COALESCE(currency, 'USD') as cur, COALESCE(SUM(commission_total), 0) as amount")
            ->groupByRaw("COALESCE(currency, 'USD')")
            ->pluck('amount', 'cur')
            ->map(fn ($v) => round((float) $v, 2))
            ->toArray();

        return [
            'total_bookings' => (int) $orders->count(),
            'revenue_by_currency' => $revenueByCurrency,
            'commission_by_currency' => $commissionByCurrency,
            'bookings_by_type' => $bookingsByType,
        ];
    }

    /**
     * 12-point revenue trend in the company's primary currency. PHP-bucketed so
     * it is portable across Postgres (prod) and SQLite (tests) — the old MySQL
     * DATE_FORMAT/YEARWEEK version 500'd on Postgres.
     *
     * @return array{currency: string|null, labels: array<int, string>, values: array<int, float>}
     */
    public function getSalesTrend(int $companyId, string $period = 'monthly', ?string $currency = null): array
    {
        $isWeekly = $period === 'weekly';
        $points = 12;

        $now = now();
        $from = $isWeekly
            ? $now->copy()->startOfWeek()->subWeeks($points - 1)
            : $now->copy()->startOfMonth()->subMonths($points - 1);

        $rows = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereHas('order', function (Builder $query) use ($companyId): void {
                $query->where('company_id', $companyId);
            })
            ->when($currency !== null, function ($query) use ($currency): void {
                $query->whereRaw("COALESCE(currency, 'USD') = ?", [$currency]);
            })
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'client_price']);

        $buckets = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->created_at);
            $key = $isWeekly ? $date->format('o-W') : $date->format('Y-m');
            $buckets[$key] = ($buckets[$key] ?? 0) + (float) $row->client_price;
        }

        $labels = [];
        $values = [];
        for ($i = 0; $i < $points; $i++) {
            $date = $isWeekly ? $from->copy()->addWeeks($i) : $from->copy()->addMonths($i);

            if ($isWeekly) {
                $key = $date->format('o-W');
                $labels[] = 'W'.$date->isoWeek.' '.$date->format('Y');
            } else {
                $key = $date->format('Y-m');
                $labels[] = $date->format('M Y');
            }

            $values[] = round((float) ($buckets[$key] ?? 0), 2);
        }

        return [
            'currency' => $currency,
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Bookings count + revenue (in the primary currency) per service type.
     * Count is currency-agnostic; revenue is summed only for the primary currency.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{type: string, bookings: int, revenue: float}>
     */
    private function serviceBreakdown(int $companyId, array $filters, ?string $currency): array
    {
        $query = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereHas('order', function (Builder $q) use ($companyId): void {
                $q->where('company_id', $companyId);
            });

        $this->applyDateFilters($query, $filters);

        if (! empty($filters['service_type']) && is_string($filters['service_type'])) {
            $query->where('invoice_type', $filters['service_type']);
        }

        $rows = $query
            ->selectRaw(
                "COALESCE(invoice_type, 'other') as type, COALESCE(currency, 'USD') as cur, "
                .'COUNT(*) as bookings, COALESCE(SUM(client_price), 0) as revenue'
            )
            ->groupByRaw("COALESCE(invoice_type, 'other'), COALESCE(currency, 'USD')")
            ->get();

        $byType = [];
        foreach ($rows as $row) {
            $type = (string) $row->type;
            if (! isset($byType[$type])) {
                $byType[$type] = ['type' => $type, 'bookings' => 0, 'revenue' => 0.0];
            }
            $byType[$type]['bookings'] += (int) $row->bookings;
            if ($currency !== null && (string) $row->cur === $currency) {
                $byType[$type]['revenue'] += (float) $row->revenue;
            }
        }

        return collect($byType)
            ->map(fn (array $r) => ['type' => $r['type'], 'bookings' => $r['bookings'], 'revenue' => round($r['revenue'], 2)])
            ->sortByDesc('bookings')
            ->values()
            ->all();
    }

    /**
     * Top 6 destinations by booking count, resolved location_id → locations.name
     * across every inventory item type.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{name: string, bookings: int}>
     */
    private function topDestinations(int $companyId, array $filters): array
    {
        $serviceFilter = ! empty($filters['service_type']) && is_string($filters['service_type'])
            ? $filters['service_type']
            : null;

        $counts = [];
        foreach (self::DESTINATION_SOURCES as $type => [$table, $fkColumn]) {
            if ($serviceFilter !== null && $serviceFilter !== $type) {
                continue;
            }

            $query = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join($table, "{$table}.id", '=', 'order_items.item_id')
                ->join('locations', 'locations.id', '=', "{$table}.{$fkColumn}")
                ->where('orders.company_id', $companyId)
                ->whereIn('orders.status', ['paid', 'confirmed'])
                ->where('order_items.item_type', $type);

            if (! empty($filters['date_from']) && is_string($filters['date_from'])) {
                $query->where('orders.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
            }
            if (! empty($filters['date_to']) && is_string($filters['date_to'])) {
                $query->where('orders.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
            }

            $rows = $query
                ->selectRaw('locations.name as destination, COUNT(*) as bookings')
                ->groupBy('locations.name')
                ->get();

            foreach ($rows as $row) {
                $name = trim((string) $row->destination);
                if ($name === '') {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + (int) $row->bookings;
            }
        }

        arsort($counts);

        return collect(array_slice($counts, 0, 6, true))
            ->map(fn ($bookings, $name) => ['name' => (string) $name, 'bookings' => (int) $bookings])
            ->values()
            ->all();
    }

    /** Dominant currency by revenue, or null when there are no paid invoices yet. */
    private function primaryCurrency(array $revenueByCurrency): ?string
    {
        if ($revenueByCurrency === []) {
            return null;
        }

        arsort($revenueByCurrency);

        return (string) array_key_first($revenueByCurrency);
    }

    /**
     * @param  Builder<Order>|Builder<Invoice>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['date_from']) && is_string($filters['date_from'])) {
            $from = Carbon::parse($filters['date_from'])->startOfDay();
            $query->where('created_at', '>=', $from);
        }

        if (! empty($filters['date_to']) && is_string($filters['date_to'])) {
            $to = Carbon::parse($filters['date_to'])->endOfDay();
            $query->where('created_at', '<=', $to);
        }
    }
}
