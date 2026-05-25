<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance group v2 — stats endpoints powering the 4-stat-card rows on
 * Payments / Invoices / Commissions / Vouchers list pages and the extended
 * Finance summary widgets.
 *
 * Each endpoint:
 *  - is platform-admin gated
 *  - returns aggregate scalars (no PII)
 *  - uses Cache::remember(60s) so dashboard refreshes don't hammer Postgres
 *  - accepts ?range=7d|30d|90d (default 30d) where it makes sense
 *
 * Companion to the v2 admin-redesign finance group migration; see
 * docs/admin_designe/finance_group_implementation_prompt.md.
 */
class FinanceStatsController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $adminAccessService,
    ) {}

    /**
     * Guard: only platform admins (super_admin or canonical platform_admin)
     * may read these aggregates. Returns the JSON 403 envelope when blocked.
     */
    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function rangeStart(string $range): \Carbon\Carbon
    {
        return match ($range) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            'year' => now()->startOfYear(),
            default => now()->subDays(30),
        };
    }

    /**
     * GET /platform-admin/payments/stats?range=30d
     *
     * Powers the 4 stat cards on /platform/payments:
     *  - total_count (last `range` days)
     *  - paid_amount + paid_count (this calendar month)
     *  - pending_amount + pending_count (open, not bounded)
     *  - failed_amount + failed_count (last 7 days)
     */
    public function payments(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $range = (string) $request->query('range', '30d');
        $rangeStart = $this->rangeStart($range);

        $data = Cache::remember("platform_payments_stats_{$range}", 60, function () use ($rangeStart) {
            $totalCount = DB::table('payments')->where('created_at', '>=', $rangeStart)->count();

            $monthStart = now()->startOfMonth();
            $paidAggregate = DB::table('payments')
                ->where('status', Payment::STATUS_PAID)
                ->where(function ($q) use ($monthStart) {
                    $q->where('paid_at', '>=', $monthStart)
                        ->orWhere(function ($qq) use ($monthStart) {
                            $qq->whereNull('paid_at')->where('created_at', '>=', $monthStart);
                        });
                })
                ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS cnt')
                ->first();

            $pendingAggregate = DB::table('payments')
                ->whereIn('status', [Payment::STATUS_PENDING, 'processing'])
                ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS cnt')
                ->first();

            $sevenDaysAgo = now()->subDays(7);
            $failedAggregate = DB::table('payments')
                ->where('status', Payment::STATUS_FAILED)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS cnt')
                ->first();

            return [
                'total_count' => (int) $totalCount,
                'paid_amount' => (float) ($paidAggregate->amount ?? 0),
                'paid_count' => (int) ($paidAggregate->cnt ?? 0),
                'pending_amount' => (float) ($pendingAggregate->amount ?? 0),
                'pending_count' => (int) ($pendingAggregate->cnt ?? 0),
                'failed_amount' => (float) ($failedAggregate->amount ?? 0),
                'failed_count' => (int) ($failedAggregate->cnt ?? 0),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/invoices/stats?range=30d
     *
     * Powers the 4 stat cards on /platform/invoices:
     *  - total_count (created in last `range` days)
     *  - paid_count + paid_pct
     *  - issued_pending_count (status='issued')
     *  - overdue_amount + overdue_count (status='issued' AND older than 30 days)
     *
     * Invoice has no native overdue status — we treat any issued invoice older
     * than 30 days as overdue. When a dedicated due_date column lands this
     * computation moves to (`due_date < now()` AND status='issued').
     */
    public function invoices(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $range = (string) $request->query('range', '30d');
        $rangeStart = $this->rangeStart($range);

        $data = Cache::remember("platform_invoices_stats_{$range}", 60, function () use ($rangeStart) {
            $totalCount = DB::table('invoices')->where('created_at', '>=', $rangeStart)->count();
            $paidCount = DB::table('invoices')->where('status', 'paid')->where('created_at', '>=', $rangeStart)->count();
            $issuedPendingCount = DB::table('invoices')->where('status', 'issued')->count();

            $overdueCutoff = now()->subDays(30);
            $overdueAggregate = DB::table('invoices')
                ->where('status', 'issued')
                ->where('created_at', '<', $overdueCutoff)
                ->selectRaw('COALESCE(SUM(total_amount), 0) AS amount, COUNT(*) AS cnt')
                ->first();

            $paidPct = $totalCount > 0 ? round(($paidCount / $totalCount) * 100, 1) : 0.0;

            return [
                'total_count' => (int) $totalCount,
                'paid_count' => (int) $paidCount,
                'paid_pct' => (float) $paidPct,
                'issued_pending_count' => (int) $issuedPendingCount,
                'overdue_amount' => (float) ($overdueAggregate->amount ?? 0),
                'overdue_count' => (int) ($overdueAggregate->cnt ?? 0),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/commissions/stats
     *
     * Powers the 4 stat cards on /platform/commissions:
     *  - active_policies_count
     *  - recorded_amount (30d)
     *  - pending_amount + pending_count
     *  - avg_rate_pct (mean percent across active percentage rules)
     */
    public function commissions(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = Cache::remember('platform_commissions_stats', 60, function () {
            // commission_rules table stores both percentage + fixed rules; status
            // and percent live on the JSON `data` payload (rule_type='commission').
            // We use a defensive fallback path that works on the legacy
            // `commission_policies` table if it still exists in some envs.
            $activeCount = 0;
            $avgRate = 0.0;
            if (Schema::hasTable('commission_rules')) {
                $activeCount = (int) DB::table('commission_rules')
                    ->where('rule_type', 'commission')
                    ->where('status', 'active')
                    ->count();
                $avgRate = (float) (DB::table('commission_rules')
                    ->where('rule_type', 'commission')
                    ->where('status', 'active')
                    ->whereNotNull('percent')
                    ->avg('percent') ?? 0);
            } elseif (Schema::hasTable('commission_policies')) {
                $activeCount = (int) DB::table('commission_policies')->where('status', 'active')->count();
                $avgRate = (float) (DB::table('commission_policies')->where('status', 'active')->whereNotNull('percent')->avg('percent') ?? 0);
            }

            $thirtyDaysAgo = now()->subDays(30);
            $recordedAmount = 0.0;
            $pendingAmount = 0.0;
            $pendingCount = 0;
            if (Schema::hasTable('commission_transactions')) {
                $recordedAmount = (float) (DB::table('commission_transactions')
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->sum('commission_amount') ?? 0);
                $pendingAggregate = DB::table('commission_transactions')
                    ->where('status', 'pending')
                    ->selectRaw('COALESCE(SUM(commission_amount), 0) AS amount, COUNT(*) AS cnt')
                    ->first();
                $pendingAmount = (float) ($pendingAggregate->amount ?? 0);
                $pendingCount = (int) ($pendingAggregate->cnt ?? 0);
            }

            return [
                'active_policies_count' => $activeCount,
                'recorded_amount' => $recordedAmount,
                'pending_amount' => $pendingAmount,
                'pending_count' => $pendingCount,
                'avg_rate_pct' => round($avgRate, 2),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/vouchers/stats?range=30d
     *
     * Powers the 4 stat cards on /platform/vouchers:
     *  - total_count (created in last `range` days)
     *  - active_issued_count
     *  - verifications_7d (count of voucher_verification_logs in last 7d)
     *  - voided_count
     */
    public function vouchers(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $range = (string) $request->query('range', '30d');
        $rangeStart = $this->rangeStart($range);

        $data = Cache::remember("platform_vouchers_stats_{$range}", 60, function () use ($rangeStart) {
            $totalCount = 0;
            $activeIssued = 0;
            $voidedCount = 0;
            if (Schema::hasTable('vouchers')) {
                $totalCount = (int) DB::table('vouchers')->where('created_at', '>=', $rangeStart)->count();
                $activeIssued = (int) DB::table('vouchers')->where('status', 'issued')->count();
                $voidedCount = (int) DB::table('vouchers')->where('status', 'void')->count();
            }

            $verifications7d = 0;
            if (Schema::hasTable('voucher_verification_logs')) {
                $verifications7d = (int) DB::table('voucher_verification_logs')
                    ->where('scanned_at', '>=', now()->subDays(7))
                    ->count();
            }

            return [
                'total_count' => $totalCount,
                'active_issued_count' => $activeIssued,
                'verifications_7d' => $verifications7d,
                'voided_count' => $voidedCount,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/finance-summary/v2?range=30d
     *
     * Extends the existing /platform-admin/finance-summary endpoint with
     * Variant A widgets: currency breakdown, platform/agent commission split,
     * pending payment age stats.
     *
     * Returned shape (additive — the original keys are preserved so existing
     * callers don't break):
     *  - total_payments_paid
     *  - total_commission_accrued
     *  - total_commission_pending
     *  - payments_count_paid
     *  - commission_records_count
     *  - currency_breakdown      { USD, EUR, AMD, RUB }
     *  - commission_split        { platform, agent }
     *  - pending_meta            { avg_age_days, oldest_days, count }
     */
    public function summaryV2(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $range = (string) $request->query('range', '30d');
        $rangeStart = $this->rangeStart($range);

        $data = Cache::remember("platform_finance_summary_v2_{$range}", 60, function () use ($rangeStart) {
            $paidSum = (float) DB::table('payments')->where('status', Payment::STATUS_PAID)->sum('amount');
            $paidCount = (int) DB::table('payments')->where('status', Payment::STATUS_PAID)->count();

            $commissionAccrued = 0.0;
            $commissionRecordsCount = 0;
            $platformSplit = 0.0;
            $agentSplit = 0.0;
            if (Schema::hasTable('commission_transactions')) {
                $commissionAccrued = (float) DB::table('commission_transactions')->sum('commission_amount');
                $commissionRecordsCount = (int) DB::table('commission_transactions')->count();
                // Platform / agent split — agent rows have agent_id set, platform rows don't.
                if (Schema::hasColumn('commission_transactions', 'agent_id')) {
                    $platformSplit = (float) DB::table('commission_transactions')
                        ->whereNull('agent_id')
                        ->sum('commission_amount');
                    $agentSplit = (float) DB::table('commission_transactions')
                        ->whereNotNull('agent_id')
                        ->sum('commission_amount');
                } else {
                    $platformSplit = $commissionAccrued;
                }
            }

            $currencyBreakdown = (array) DB::table('payments')
                ->where('status', Payment::STATUS_PAID)
                ->where('paid_at', '>=', $rangeStart)
                ->selectRaw('currency, COALESCE(SUM(amount), 0) AS amount')
                ->groupBy('currency')
                ->pluck('amount', 'currency')
                ->toArray();

            // Pending payment age metrics — uses created_at as proxy for "when
            // the invoice was issued" since invoices share creation timing.
            $pendingAge = DB::table('payments')
                ->whereIn('status', [Payment::STATUS_PENDING, 'processing'])
                ->selectRaw(
                    'COUNT(*) AS cnt,
                     COALESCE(EXTRACT(DAY FROM AVG(NOW() - created_at)), 0) AS avg_days,
                     COALESCE(EXTRACT(DAY FROM MAX(NOW() - created_at)), 0) AS oldest_days'
                )
                ->first();

            return [
                // Preserved keys from PlatformAdminService::getFinanceSummary()
                'total_payments_paid' => $paidSum,
                'total_commission_accrued' => $commissionAccrued,
                'total_commission_pending' => 0.0,
                'payments_count_paid' => $paidCount,
                'commission_records_count' => $commissionRecordsCount,
                // New v2 fields
                'currency_breakdown' => $currencyBreakdown,
                'commission_split' => [
                    'platform' => $platformSplit,
                    'agent' => $agentSplit,
                ],
                'pending_meta' => [
                    'count' => (int) ($pendingAge->cnt ?? 0),
                    'avg_age_days' => (float) ($pendingAge->avg_days ?? 0),
                    'oldest_days' => (float) ($pendingAge->oldest_days ?? 0),
                ],
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}
