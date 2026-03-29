<?php

namespace App\Services\Analytics;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Offer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * Single assembly path for operator (company) statistics — used by API and admin web JSON.
     *
     * @return array{stats: array<string, mixed>, trend: array<string, array<int, float|string>>}
     */
    public function buildOperatorCompanyStatisticsPayload(int $companyId, Request $request): array
    {
        $filters = [
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'service_type' => $request->query('service_type'),
        ];

        $stats = $this->getCompanyStats($companyId, $filters);
        $trend = $this->getSalesTrend(
            $companyId,
            (string) $request->query('period', 'monthly')
        );

        $activeOffers = Offer::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [Offer::STATUS_ACTIVE, Offer::STATUS_PUBLISHED])
            ->count();

        return [
            'stats' => [
                ...$stats,
                'active_offers' => $activeOffers,
            ],
            'trend' => $trend,
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
            ->whereHas('booking', function (Builder $query) use ($companyId): void {
                $query->where('company_id', $companyId);
            });

        $bookings = Booking::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['sold', 'reserved', 'Sold', 'Reserved']);

        $this->applyDateFilters($paidInvoices, $filters);
        $this->applyDateFilters($bookings, $filters);

        if (! empty($filters['service_type']) && is_string($filters['service_type'])) {
            $serviceType = $filters['service_type'];

            $paidInvoices->where('invoice_type', $serviceType);
            $bookings->whereHas('items.offer', function (Builder $query) use ($serviceType): void {
                $query->where('type', $serviceType);
            });
        }

        $bookingsByType = (clone $paidInvoices)
            ->select('invoice_type', DB::raw('COUNT(*) as total'))
            ->groupBy('invoice_type')
            ->pluck('total', 'invoice_type')
            ->toArray();

        return [
            'total_revenue' => (float) (clone $paidInvoices)->sum('client_price'),
            'total_bookings' => (int) $bookings->count(),
            'commission_earned' => (float) (clone $paidInvoices)->sum('commission_total'),
            'bookings_by_type' => $bookingsByType,
        ];
    }

    /**
     * @return array<string, array<int, float|string>>
     */
    public function getSalesTrend(int $companyId, string $period = 'monthly'): array
    {
        $isWeekly = $period === 'weekly';
        $points = 12;
        $labels = [];
        $values = [];

        $now = now();
        $from = $isWeekly
            ? $now->copy()->startOfWeek()->subWeeks($points - 1)
            : $now->copy()->startOfMonth()->subMonths($points - 1);

        $rawRows = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereHas('booking', function (Builder $query) use ($companyId): void {
                $query->where('company_id', $companyId);
            })
            ->where('created_at', '>=', $from)
            ->selectRaw(
                $isWeekly
                    ? "YEARWEEK(created_at, 1) as grp_key, SUM(client_price) as revenue"
                    : "DATE_FORMAT(created_at, '%Y-%m') as grp_key, SUM(client_price) as revenue"
            )
            ->groupBy('grp_key')
            ->pluck('revenue', 'grp_key')
            ->toArray();

        for ($i = 0; $i < $points; $i++) {
            $date = $isWeekly
                ? $from->copy()->addWeeks($i)
                : $from->copy()->addMonths($i);

            if ($isWeekly) {
                $key = (int) $date->format('oW');
                $labels[] = 'W'.(string) $date->isoWeek.' '.$date->format('Y');
            } else {
                $key = $date->format('Y-m');
                $labels[] = $date->format('M Y');
            }

            $values[] = (float) ($rawRows[$key] ?? 0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * @param  Builder<Booking>|Builder<Invoice>  $query
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
