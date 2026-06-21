<?php

namespace App\Services\Admin;

use App\Models\Approval;
use App\Models\Company;
use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Packages\PackageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PlatformAdminService
{
    public function __construct(
        private PackageService $packageService
    ) {}

    /**
     * Dashboard headline numbers. Phase 5: when $scopeCompanyIds is non-null
     * (a non-super caller), every metric is restricted to those companies so
     * an operator's dashboard shows THEIR numbers, never the platform totals.
     * null = super = platform-wide (unchanged). The cache key carries the
     * scope suffix so a scoped caller never reads the super global entry.
     *
     * @param  ?list<int>  $scopeCompanyIds
     */
    public function getPlatformStats(?array $scopeCompanyIds = null, string $cacheSuffix = 'all', ?string $range = null): array
    {
        // Roadmap 10.06 §5 — optional ?range= (7d/30d/90d/year) filters the
        // FLOW aggregates (orders/bookings/package-orders) by created_at.
        // STOCK totals (companies/offers/packages/users/commission records)
        // deliberately stay unfiltered — "total operators" filtered to a date
        // window would read as zero and lie.
        $rangeStart = match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            'year' => now()->startOfYear(),
            default => null,
        };

        $cacheKey = 'platform_stats_'.$cacheSuffix.($rangeStart !== null ? '_'.$range : '');

        return Cache::remember($cacheKey, 300, function () use ($scopeCompanyIds, $rangeStart) {
            // Apply a company-id filter to a builder when scoped; [] → 1=0.
            $scoped = function ($query, string $column) use ($scopeCompanyIds) {
                if ($scopeCompanyIds === null) {
                    return $query;
                }
                if (count($scopeCompanyIds) === 0) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereIn($column, $scopeCompanyIds);
            };
            // Orders belong to the company as seller OR referring agent.
            $scopedOrders = function ($query) use ($scopeCompanyIds) {
                if ($scopeCompanyIds === null) {
                    return $query;
                }
                if (count($scopeCompanyIds) === 0) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->where(function ($q) use ($scopeCompanyIds) {
                    $q->whereIn('company_id', $scopeCompanyIds)
                        ->orWhereIn('agent_company_id', $scopeCompanyIds);
                });
            };

            $companies = fn () => $scoped(DB::table('companies'), 'id');
            $offers = fn () => $scoped(DB::table('offers'), 'company_id');
            $packages = fn () => $scoped(DB::table('packages'), 'company_id');
            $orders = function () use ($scopedOrders, $rangeStart) {
                $q = $scopedOrders(DB::table('orders'));

                return $rangeStart !== null ? $q->where('orders.created_at', '>=', $rangeStart) : $q;
            };

            // "users_total": super → all users; scoped → distinct staff of the
            // caller's companies (a count of platform users is meaningless to
            // an operator).
            if ($scopeCompanyIds === null) {
                $usersTotal = (int) DB::table('users')->count();
            } elseif (count($scopeCompanyIds) === 0) {
                $usersTotal = 0;
            } else {
                $usersTotal = (int) DB::table('user_company')
                    ->whereIn('company_id', $scopeCompanyIds)
                    ->distinct()
                    ->count('user_id');
            }

            return [
                'companies_total' => (int) $companies()->count(),
                'companies_active' => (int) $companies()->where('governance_status', 'active')->count(),
                'companies_suspended' => (int) $companies()->where('governance_status', 'suspended')->count(),
                'companies_sellers' => (int) $companies()->where('is_seller', true)->count(),
                'offers_total' => (int) $offers()->count(),
                'offers_published' => (int) $offers()->where('status', 'published')->count(),
                'packages_total' => (int) $packages()->count(),
                'packages_active' => (int) $packages()->where('status', 'active')->count(),
                'package_orders_total' => (int) $orders()->where('metadata->legacy_origin', 'package_order')->count(),
                'package_orders_paid' => (int) $orders()->where('metadata->legacy_origin', 'package_order')->where('status', 'paid')->count(),
                'package_orders_pending_payment' => (int) $orders()->where('metadata->legacy_origin', 'package_order')->where('status', 'pending_payment')->count(),
                'bookings_total' => (int) $orders()->where('metadata->legacy_origin', 'booking')->count(),
                'users_total' => $usersTotal,
                'commission_records_total' => (int) $scoped(DB::table('commission_transactions'), 'seller_id')->count(),
                'commission_records_accrued' => 0,
            ];
        });
    }

    /**
     * @param  array{governance_status?:string,is_seller?:bool|null,search?:string,type?:string,sort_by?:string,sort_dir?:string,archive_filter?:string}  $filters
     */
    public function listCompanies(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Company::query()
            ->withCount([
                'sellerPermissions as active_seller_permissions_count' => function ($q): void {
                    $q->where('status', 'active');
                },
            ]);

        // Tenant scope: non-super callers see only their own company row(s).
        if (array_key_exists('scope_company_ids', $filters)) {
            $scopeIds = array_values(array_filter(
                array_map('intval', (array) $filters['scope_company_ids']),
                static fn ($id) => $id > 0,
            ));
            $query->whereIn('id', $scopeIds ?: [0]);
        }

        // Phase 7.2 — archive visibility. Default: hide archived rows.
        // Accepts archive_filter = active|archived|all.
        $archiveFilter = $filters['archive_filter'] ?? 'active';
        if ($archiveFilter === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif ($archiveFilter !== 'all') {
            $query->whereNull('archived_at');
        }

        if (! empty($filters['governance_status'])) {
            $query->where('governance_status', $filters['governance_status']);
        }

        if (array_key_exists('is_seller', $filters) && $filters['is_seller'] !== null) {
            $query->where('is_seller', (bool) $filters['is_seller']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('legal_name', 'ilike', $term)
                    ->orWhere('slug', 'ilike', $term);
            });
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $allowedSorts = ['id', 'name', 'type', 'governance_status', 'is_seller', 'created_at'];
        $sortBy = isset($filters['sort_by']) && in_array($filters['sort_by'], $allowedSorts, true)
            ? $filters['sort_by']
            : 'id';
        $sortDir = (isset($filters['sort_dir']) && strtolower((string) $filters['sort_dir']) === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage);
    }

    /**
     * Phase 7.2 — admin archive: hide from active listings but preserve
     * orders/contracts/inventory. Reversible via restoreCompany().
     */
    public function archiveCompany(Company $company, User $actor, string $reason): Company
    {
        $company->archived_at = now();
        $company->archived_by_user_id = $actor->id;
        $company->archived_reason = mb_substr($reason, 0, 500);
        $company->save();

        app(AuditService::class)->log([
            'category' => 'company_admin_action',
            'subject_type' => 'Company',
            'subject_id' => (string) $company->id,
            'action' => 'archive',
            'actor' => $actor,
            'context' => ['reason' => $reason, 'company_name' => $company->name],
        ]);

        return $company->fresh();
    }

    /**
     * Phase 7.2 — undo archive. Clears the archived markers; row reappears
     * in default admin listings.
     */
    public function restoreCompany(Company $company, User $actor): Company
    {
        $previousReason = $company->archived_reason;
        $company->archived_at = null;
        $company->archived_by_user_id = null;
        $company->archived_reason = null;
        $company->save();

        app(AuditService::class)->log([
            'category' => 'company_admin_action',
            'subject_type' => 'Company',
            'subject_id' => (string) $company->id,
            'action' => 'restore',
            'actor' => $actor,
            'context' => ['previous_reason' => $previousReason, 'company_name' => $company->name],
        ]);

        return $company->fresh();
    }

    public function changeCompanyGovernanceStatus(
        Company $company,
        User $actor,
        string $newStatus,
        ?string $reason = null
    ): Company {
        if (! in_array($newStatus, Company::GOVERNANCE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'governance_status' => ['Invalid governance status.'],
            ]);
        }

        $company->governance_status = $newStatus;
        if ($newStatus === 'suspended' && $company->is_seller) {
            $company->is_seller = false;
        }
        $company->save();

        Approval::query()->create([
            'entity_type' => 'company',
            'entity_id' => $company->id,
            'status' => $newStatus,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'decision_notes' => $reason,
            'priority' => 'normal',
        ]);

        return $company;
    }

    /**
     * @param  array{status?:string,entity_type?:string}  $filters
     */
    public function listApprovals(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Approval::query()
            ->with(['requestedBy', 'approver', 'reviewedBy'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        return $query->paginate($perPage);
    }

    public function approveApproval(Approval $approval, User $actor, ?string $decisionNotes = null): Approval
    {
        if (! in_array($approval->status, ['pending', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'approval' => ['Only pending or under-review approvals can be approved.'],
            ]);
        }

        $approval->status = 'approved';
        $approval->reviewed_by = $actor->id;
        $approval->reviewed_at = now();
        $approval->approved_by = $actor->id;
        $approval->approved_at = now();
        $approval->decision_notes = $decisionNotes;
        $approval->save();

        $approval->load(['requestedBy', 'approver', 'reviewedBy']);

        return $approval;
    }

    public function rejectApproval(Approval $approval, User $actor, ?string $decisionNotes = null): Approval
    {
        if (! in_array($approval->status, ['pending', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'approval' => ['Only pending or under-review approvals can be rejected.'],
            ]);
        }

        $approval->status = 'rejected';
        $approval->reviewed_by = $actor->id;
        $approval->reviewed_at = now();
        $approval->decision_notes = $decisionNotes;
        $approval->save();

        $approval->load(['requestedBy', 'approver', 'reviewedBy']);

        return $approval;
    }

    /**
     * @param  array{status?:string,payment_status?:string,company_id?:int}  $filters
     */
    public function listAllPackageOrders(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Order::query()
            ->where('metadata->legacy_origin', 'package_order')
            ->with(['user', 'company', 'items'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('status', $filters['payment_status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        $this->applyOrderCompanyScope($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Tenant scope for Order-based list queries: restrict to the caller's own
     * companies (seller via company_id OR referrer via agent_company_id) when
     * the controller set `scope_company_ids` (non-super callers only). An
     * empty list means "owns no company" → zero rows, never a leak. Shared by
     * bookings + package orders so the rule stays identical.
     *
     * @param  Builder<Order>  $query
     * @param  array<string,mixed>  $filters
     */
    private function applyOrderCompanyScope($query, array $filters): void
    {
        if (! array_key_exists('scope_company_ids', $filters)) {
            return;
        }
        $scopeIds = array_values(array_filter(
            array_map('intval', (array) $filters['scope_company_ids']),
            static fn ($id) => $id > 0,
        ));
        if (count($scopeIds) === 0) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function ($q) use ($scopeIds) {
            $q->whereIn('company_id', $scopeIds)
                ->orWhereIn('agent_company_id', $scopeIds);
        });

        // Phase 3 Layer-B (within-company row scope): when the controller set
        // a non-null scope_attribution_user_id (a plain employee, not the
        // owner and without <module>.view_all), narrow further to the orders
        // that employee personally sold. Null/absent = owner or view_all =
        // whole company (no extra filter). The column was added 2026-06-01
        // (orders.sold_by_user_id) for CRM employee-sales attribution.
        if (array_key_exists('scope_attribution_user_id', $filters)
            && $filters['scope_attribution_user_id'] !== null) {
            $query->where('sold_by_user_id', (int) $filters['scope_attribution_user_id']);
        }
    }

    /**
     * Bookings group v2 — list "All bookings" (non-package orders).
     *
     * @param  array{status?:?string,from?:?string,to?:?string,search?:?string,company_id?:?int,service_type?:?string}  $filters
     */
    public function listAllBookings(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Order::query()
            ->whereNull('deleted_at')
            ->where(function ($q) {
                // Exclude package orders — those belong to /platform/package-orders.
                $q->whereNull('metadata')
                    ->orWhereRaw("(metadata->>'legacy_origin') IS DISTINCT FROM 'package_order'");
            })
            ->with(['user:id,name,email', 'company:id,name', 'agentCompany:id,name', 'items'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', (string) $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', (string) $filters['to'].' 23:59:59');
        }
        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }
        // Tenant scope (2026-06-02): non-super callers see ONLY their own
        // companies' bookings. The controller sets scope_company_ids for
        // everyone except super-admins. An empty list means the caller owns
        // no company → they see nothing (prevents the old leak where an
        // operator landed on /platform/bookings and saw every company's data).
        $this->applyOrderCompanyScope($query, $filters);
        // Phase 4G (2026-05-31) — user_id filter so the new Directory→People
        // detail page can fetch a customer's recent bookings inline.
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['search'])) {
            $needle = '%'.str_replace('%', '\\%', (string) $filters['search']).'%';
            $query->where(function ($q) use ($needle) {
                $q->where('order_number', 'ILIKE', $needle)
                    ->orWhereHas('user', fn ($qq) => $qq->where('name', 'ILIKE', $needle)->orWhere('email', 'ILIKE', $needle))
                    ->orWhereHas('company', fn ($qq) => $qq->where('name', 'ILIKE', $needle));
            });
        }
        if (! empty($filters['service_type'])) {
            $query->whereHas('items', fn ($qq) => $qq->where('module_type', (string) $filters['service_type']));
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{status?:string}  $filters
     */
    public function listAllPayments(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->buildPaymentsQuery($filters)->paginate($perPage);
    }

    /**
     * Phase 7.7 — unpaginated payments fetch for CSV export.
     * Caller responsibility to bound the row count (controller takes(N)).
     *
     * @param  array{status?:?string,from?:?string,to?:?string}  $filters
     * @return Collection<int, Payment>
     */
    public function listPaymentsForExport(array $filters = []): Collection
    {
        return $this->buildPaymentsQuery($filters)->get();
    }

    /**
     * @param  array{status?:?string,from?:?string,to?:?string}  $filters
     */
    private function buildPaymentsQuery(array $filters)
    {
        // Finance group v2 — eager-load invoice→order→company so the Payments
        // table can render an avatar + company name per row (the chain is
        // Payment.invoice (BelongsTo Invoice) → Invoice.order (BelongsTo Order)
        // → Order.company (BelongsTo Company). Invoice itself has no
        // company_id column, so the join walks through Order.
        $query = Payment::query()
            ->with(['invoice.order.company:id,name', 'invoice.order.agentCompany:id,name'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        // Phase 7.7 — date range on payments.created_at (paid_at can be null).
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        // Tenant scope: a payment belongs to the company that owns its order
        // (seller via company_id, or referring agent via agent_company_id).
        // Non-super callers are restricted to their own companies.
        if (array_key_exists('scope_company_ids', $filters)) {
            $scopeIds = array_values(array_filter(
                array_map('intval', (array) $filters['scope_company_ids']),
                static fn ($id) => $id > 0,
            ));
            if (count($scopeIds) === 0) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('invoice.order', function ($q) use ($scopeIds) {
                    $q->whereIn('company_id', $scopeIds)
                        ->orWhereIn('agent_company_id', $scopeIds);
                });
            }
        }

        return $query;
    }

    /**
     * Finance headline numbers. Phase 5: scoped to the caller's companies when
     * $scopeCompanyIds is non-null — payments via invoice→order→company,
     * commissions via commission_transactions.seller_id. null = super =
     * platform-wide. Cache key carries the scope suffix (no cross-tenant leak).
     *
     * @param  ?list<int>  $scopeCompanyIds
     */
    public function getFinanceSummary(?array $scopeCompanyIds = null, string $cacheSuffix = 'all'): array
    {
        return Cache::remember('platform_finance_summary_'.$cacheSuffix, 300, function () use ($scopeCompanyIds) {
            // payments → invoice → order → company (payments have no company_id).
            $applyPaymentScope = function ($q) use ($scopeCompanyIds) {
                if ($scopeCompanyIds === null) {
                    return $q;
                }
                if (count($scopeCompanyIds) === 0) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereExists(function ($sub) use ($scopeCompanyIds) {
                    $sub->selectRaw('1')
                        ->from('invoices')
                        ->join('orders', 'orders.id', '=', 'invoices.order_id')
                        ->whereColumn('invoices.id', 'payments.invoice_id')
                        ->where(function ($w) use ($scopeCompanyIds) {
                            $w->whereIn('orders.company_id', $scopeCompanyIds)
                                ->orWhereIn('orders.agent_company_id', $scopeCompanyIds);
                        });
                });
            };
            $applyCommScope = function ($q) use ($scopeCompanyIds) {
                if ($scopeCompanyIds === null) {
                    return $q;
                }
                if (count($scopeCompanyIds) === 0) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereIn('seller_id', $scopeCompanyIds);
            };

            $paidSum = $applyPaymentScope(DB::table('payments'))->where('status', Payment::STATUS_PAID)->sum('amount');
            $paidCount = $applyPaymentScope(DB::table('payments'))->where('status', Payment::STATUS_PAID)->count();
            $commissionTotalSum = $applyCommScope(DB::table('commission_transactions'))->sum('commission_amount');
            $commissionCount = $applyCommScope(DB::table('commission_transactions'))->count();

            // Phase 1 §1 — per-currency breakdowns. The scalars above sum
            // ACROSS currencies; the *_by_currency siblings partition each total
            // by currency (GROUP BY). NOTE: commission_transactions has NO plain
            // `currency` column — `commission_amount` is denominated in
            // `commission_currency`, so we group by that. Original scalars kept.
            $paidByCurrency = $applyPaymentScope(DB::table('payments'))
                ->where('status', Payment::STATUS_PAID)
                ->selectRaw('currency, COALESCE(SUM(amount), 0) AS amount')
                ->groupBy('currency')
                ->pluck('amount', 'currency')
                ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => round((float) $v, 2)])
                ->toArray();
            $commissionAccruedByCurrency = $applyCommScope(DB::table('commission_transactions'))
                ->selectRaw('commission_currency, COALESCE(SUM(commission_amount), 0) AS amount')
                ->groupBy('commission_currency')
                ->pluck('amount', 'commission_currency')
                ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => round((float) $v, 2)])
                ->toArray();

            // §8 — real pending-payout figure (was hardcoded 0.0): net still owed
            // to the in-scope sellers via the supplier_entitlements ledger.
            $pendingPayouts = 0.0;
            $pendingPayoutsByCurrency = [];
            if (Schema::hasTable('supplier_entitlements')) {
                $pendingQuery = DB::table('supplier_entitlements')
                    ->whereIn('status', ['pending', 'accrued', 'payable']);
                if ($scopeCompanyIds !== null) {
                    $pendingQuery->whereIn('company_id', count($scopeCompanyIds) > 0 ? $scopeCompanyIds : [-1]);
                }
                $pendingPayouts = (float) (clone $pendingQuery)->sum('net_amount');
                $pendingPayoutsByCurrency = (clone $pendingQuery)
                    ->selectRaw('currency, COALESCE(SUM(net_amount), 0) AS amount')
                    ->groupBy('currency')
                    ->pluck('amount', 'currency')
                    ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => round((float) $v, 2)])
                    ->toArray();
            }

            return [
                'total_payments_paid' => (float) ($paidSum ?? 0),
                'total_commission_accrued' => (float) ($commissionTotalSum ?? 0),
                'total_commission_pending' => $pendingPayouts,
                'pending_payouts' => $pendingPayouts,
                'payments_count_paid' => (int) $paidCount,
                'commission_records_count' => (int) $commissionCount,
                'total_payments_paid_by_currency' => $paidByCurrency,
                'total_commission_accrued_by_currency' => $commissionAccruedByCurrency,
                'total_commission_pending_by_currency' => $pendingPayoutsByCurrency,
                'pending_payouts_by_currency' => $pendingPayoutsByCurrency,
            ];
        });
    }

    /**
     * @param  array{status?:string,company_id?:int}  $filters
     */
    public function listAllPackages(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Package::query()
            ->with(['offer', 'company'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        return $query->paginate($perPage);
    }

    public function forceDeactivatePackage(Package $package, User $actor, ?string $reason = null): Package
    {
        $this->packageService->deactivate($package);

        Approval::query()->create([
            'entity_type' => 'package',
            'entity_id' => $package->id,
            'status' => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'decision_notes' => $reason ?? 'Force deactivated by platform admin',
            'priority' => 'normal',
        ]);

        $package->load(['offer', 'company']);

        return $package;
    }
}
