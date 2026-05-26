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
 * Marketplace ops group v2 — stats endpoints powering the 4-stat-card rows on
 * Approval queue, Companies, Seller applications, Contracts, Contract templates,
 * Audit logs, and Unverified accounts list pages.
 *
 * Each endpoint:
 *  - is platform-admin gated (super_admin or canonical platform_admin)
 *  - returns aggregate scalars (no PII)
 *  - uses Cache::remember(60s) so dashboard refreshes don't hammer Postgres
 *  - defensively checks Schema::hasTable / hasColumn so missing migrations
 *    return safe zeroes instead of 500
 *
 * Companion to the v2 admin-redesign Marketplace ops group migration; see
 * docs/admin_designe/marketplace_ops/marketplace_ops_implementation_prompt.md.
 */
class MarketplaceStatsController extends Controller
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
     * GET /platform-admin/approvals/stats
     *
     * Powers the 4 stat cards on /platform/approvals. Counts grouped by the
     * Approval.entity_type values (company / company_application,
     * seller_request, offer/package, review).
     *
     * Response: { company_apps, seller_apps, offers_pending, reviews_pending,
     *             new_today_company, new_today_seller, today_offer, today_review }
     */
    public function approvals(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = Cache::remember('marketplace_approvals_stats', 60, function (): array {
            $hasApprovals = Schema::hasTable('approvals');
            $hasReviews = Schema::hasTable('reviews');

            $byTypeOpen = $hasApprovals
                ? DB::table('approvals')
                    ->select('entity_type', DB::raw('COUNT(*) AS cnt'))
                    ->whereIn('status', ['pending', 'under_review'])
                    ->groupBy('entity_type')
                    ->pluck('cnt', 'entity_type')
                    ->all()
                : [];

            $todayStart = now()->startOfDay();
            $byTypeNewToday = $hasApprovals
                ? DB::table('approvals')
                    ->select('entity_type', DB::raw('COUNT(*) AS cnt'))
                    ->where('created_at', '>=', $todayStart)
                    ->groupBy('entity_type')
                    ->pluck('cnt', 'entity_type')
                    ->all()
                : [];

            $companyApps = (int) (($byTypeOpen['company'] ?? 0) + ($byTypeOpen['company_application'] ?? 0));
            $sellerApps = (int) ($byTypeOpen['seller_request'] ?? 0);
            $offerPending = (int) (($byTypeOpen['offer'] ?? 0) + ($byTypeOpen['package'] ?? 0));

            $reviewsPending = 0;
            if ($hasReviews && Schema::hasColumn('reviews', 'status')) {
                $reviewsPending = (int) DB::table('reviews')->where('status', 'pending')->count();
            }

            return [
                'company_apps' => $companyApps,
                'seller_apps' => $sellerApps,
                'offers_pending' => $offerPending,
                'reviews_pending' => $reviewsPending,
                'new_today_company' => (int) (($byTypeNewToday['company'] ?? 0) + ($byTypeNewToday['company_application'] ?? 0)),
                'new_today_seller' => (int) ($byTypeNewToday['seller_request'] ?? 0),
                'new_today_offer' => (int) (($byTypeNewToday['offer'] ?? 0) + ($byTypeNewToday['package'] ?? 0)),
                'new_today_review' => 0,
                'total_pending' => $companyApps + $sellerApps + $offerPending + $reviewsPending,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/companies/stats
     */
    public function companies(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = Cache::remember('marketplace_companies_stats', 60, function (): array {
            if (! Schema::hasTable('companies')) {
                return ['total' => 0, 'active' => 0, 'sellers' => 0, 'archived' => 0];
            }

            $hasArchived = Schema::hasColumn('companies', 'archived_at');
            $hasGovernance = Schema::hasColumn('companies', 'governance_status');
            $hasIsSeller = Schema::hasColumn('companies', 'is_seller');

            $baseTotal = DB::table('companies');
            if ($hasArchived) $baseTotal = $baseTotal->whereNull('archived_at');
            $total = (int) (clone $baseTotal)->count();

            $active = $hasGovernance
                ? (int) (clone $baseTotal)->where('governance_status', 'active')->count()
                : $total;

            $sellers = $hasIsSeller
                ? (int) (clone $baseTotal)->where('is_seller', true)->count()
                : 0;

            $archived = $hasArchived
                ? (int) DB::table('companies')->whereNotNull('archived_at')->count()
                : 0;

            return compact('total', 'active', 'sellers', 'archived');
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/seller-applications/stats
     */
    public function sellerApplications(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = Cache::remember('marketplace_seller_applications_stats', 60, function (): array {
            if (! Schema::hasTable('company_seller_applications')) {
                return ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'avg_review_hours' => null];
            }

            $byStatus = DB::table('company_seller_applications')
                ->select('status', DB::raw('COUNT(*) AS cnt'))
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->all();

            $pending = (int) (($byStatus['pending'] ?? 0) + ($byStatus['under_review'] ?? 0));
            $approved = (int) ($byStatus['approved'] ?? 0);
            $rejected = (int) ($byStatus['rejected'] ?? 0);

            // Average review time (hours from applied_at → reviewed_at) over last 90d.
            $avgReviewHours = null;
            if (Schema::hasColumn('company_seller_applications', 'reviewed_at') &&
                Schema::hasColumn('company_seller_applications', 'applied_at')) {
                $driver = Schema::getConnection()->getDriverName();
                $avgRow = DB::table('company_seller_applications')
                    ->whereNotNull('reviewed_at')
                    ->where('reviewed_at', '>=', now()->subDays(90))
                    ->selectRaw($driver === 'pgsql'
                        ? 'AVG(EXTRACT(EPOCH FROM (reviewed_at - applied_at)) / 3600) AS hrs'
                        : 'AVG(TIMESTAMPDIFF(SECOND, applied_at, reviewed_at) / 3600) AS hrs')
                    ->first();
                if ($avgRow && $avgRow->hrs !== null) {
                    $avgReviewHours = round((float) $avgRow->hrs, 1);
                }
            }

            return [
                'pending' => $pending,
                'approved' => $approved,
                'rejected' => $rejected,
                'avg_review_hours' => $avgReviewHours,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/contracts/stats
     */
    public function contracts(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = Cache::remember('marketplace_contracts_stats', 60, function (): array {
            if (! Schema::hasTable('contracts')) {
                return ['total' => 0, 'signed' => 0, 'drafts' => 0, 'expiring_30d' => 0];
            }

            $base = DB::table('contracts')->whereNull('deleted_at');

            $total = (int) (clone $base)->count();
            $signed = (int) (clone $base)->whereIn('status', ['countersigned', 'active', 'signed_by_a', 'signed_by_b'])->count();
            $drafts = (int) (clone $base)->where('status', 'draft')->count();

            $expiring30d = 0;
            if (Schema::hasColumn('contracts', 'expiry_date')) {
                $expiring30d = (int) (clone $base)
                    ->whereIn('status', ['countersigned', 'active', 'signed_by_a', 'signed_by_b'])
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count();
            }

            return [
                'total' => $total,
                'signed' => $signed,
                'drafts' => $drafts,
                'expiring_30d' => $expiring30d,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/contract-templates/stats
     */
    public function contractTemplates(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = Cache::remember('marketplace_contract_templates_stats', 60, function (): array {
            if (! Schema::hasTable('contract_templates')) {
                return ['total' => 0, 'published' => 0, 'drafts' => 0, 'contracts_created' => 0];
            }

            $total = (int) DB::table('contract_templates')->count();
            $published = Schema::hasColumn('contract_templates', 'active')
                ? (int) DB::table('contract_templates')->where('active', true)->count()
                : $total;
            $drafts = $total - $published;

            $contractsCreated = Schema::hasTable('contracts')
                ? (int) DB::table('contracts')->whereNull('deleted_at')->count()
                : 0;

            return [
                'total' => $total,
                'published' => $published,
                'drafts' => $drafts,
                'contracts_created' => $contractsCreated,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/audit-logs/stats?range=30d
     */
    public function auditLogs(Request $request): JsonResponse
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

        $data = Cache::remember("marketplace_audit_logs_stats_{$range}", 60, function () use ($rangeStart): array {
            if (! Schema::hasTable('audit_logs')) {
                return ['total' => 0, 'active_admins' => 0, 'suspicious' => 0, 'failed' => 0];
            }

            $base = DB::table('audit_logs')->where('created_at', '>=', $rangeStart);

            $total = (int) (clone $base)->count();
            $activeAdmins = (int) (clone $base)
                ->where('actor_type', 'user')
                ->distinct()
                ->count('actor_id');

            $suspicious = (int) (clone $base)
                ->whereIn('action', ['delete', 'hard_delete', 'force_delete', 'override', 'bypass'])
                ->count();

            $failed = (int) (clone $base)
                ->where(function ($q) {
                    $q->where('action', 'like', '%fail%')
                        ->orWhere('action', 'like', '%error%')
                        ->orWhere('action', 'login.failed')
                        ->orWhere('category', 'security');
                })
                ->count();

            return [
                'total' => $total,
                'active_admins' => $activeAdmins,
                'suspicious' => $suspicious,
                'failed' => $failed,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /platform-admin/unverified-accounts/stats
     */
    public function unverifiedAccounts(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = Cache::remember('marketplace_unverified_accounts_stats', 60, function (): array {
            if (! Schema::hasTable('users')) {
                return ['total' => 0, 'no_email' => 0, 'no_phone' => 0, 'old_7d' => 0];
            }

            $hasEmailVerified = Schema::hasColumn('users', 'email_verified_at');
            $hasPhoneVerified = Schema::hasColumn('users', 'phone_verified_at');

            $base = DB::table('users')->whereNull('deleted_at');
            $base = $base->where(function ($q) use ($hasEmailVerified, $hasPhoneVerified) {
                if ($hasEmailVerified) $q->whereNull('email_verified_at');
                if ($hasPhoneVerified) $q->orWhereNull('phone_verified_at');
            });

            $total = (int) (clone $base)->count();

            $noEmail = $hasEmailVerified
                ? (int) DB::table('users')->whereNull('deleted_at')->whereNull('email_verified_at')->count()
                : 0;

            $noPhone = $hasPhoneVerified
                ? (int) DB::table('users')->whereNull('deleted_at')->whereNull('phone_verified_at')->count()
                : 0;

            $old7d = (int) (clone $base)->where('created_at', '<', now()->subDays(7))->count();

            return [
                'total' => $total,
                'no_email' => $noEmail,
                'no_phone' => $noPhone,
                'old_7d' => $old7d,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}
