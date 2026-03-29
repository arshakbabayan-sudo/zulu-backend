<?php

namespace App\Services\Admin;

use App\Models\Approval;
use App\Models\Company;
use App\Models\Package;
use App\Models\PackageOrder;
use App\Models\Payment;
use App\Models\User;
use App\Services\Packages\PackageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformAdminService
{
    public function __construct(
        private PackageService $packageService
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function getPlatformStats(): array
    {
        return [
            'companies_total' => (int) DB::table('companies')->count(),
            'companies_active' => (int) DB::table('companies')->where('governance_status', 'active')->count(),
            'companies_suspended' => (int) DB::table('companies')->where('governance_status', 'suspended')->count(),
            'companies_sellers' => (int) DB::table('companies')->where('is_seller', true)->count(),
            'offers_total' => (int) DB::table('offers')->count(),
            'offers_published' => (int) DB::table('offers')->where('status', 'published')->count(),
            'packages_total' => (int) DB::table('packages')->count(),
            'packages_active' => (int) DB::table('packages')->where('status', 'active')->count(),
            'package_orders_total' => (int) DB::table('package_orders')->count(),
            'package_orders_paid' => (int) DB::table('package_orders')->where('status', 'paid')->count(),
            'package_orders_pending_payment' => (int) DB::table('package_orders')->where('status', 'pending_payment')->count(),
            'bookings_total' => (int) DB::table('bookings')->count(),
            'users_total' => (int) DB::table('users')->count(),
            'commission_records_total' => (int) DB::table('commission_records')->count(),
            'commission_records_accrued' => (int) DB::table('commission_records')->where('status', 'accrued')->count(),
        ];
    }

    /**
     * @param  array{governance_status?:string,is_seller?:bool|null,search?:string,type?:string}  $filters
     */
    public function listCompanies(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Company::query()
            ->withCount([
                'sellerPermissions as active_seller_permissions_count' => function ($q): void {
                    $q->where('status', 'active');
                },
            ])
            ->orderByDesc('id');

        if (! empty($filters['governance_status'])) {
            $query->where('governance_status', $filters['governance_status']);
        }

        if (array_key_exists('is_seller', $filters) && $filters['is_seller'] !== null) {
            $query->where('is_seller', (bool) $filters['is_seller']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('legal_name', 'like', $term)
                    ->orWhere('slug', 'like', $term);
            });
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->paginate($perPage);
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

        return $company->fresh();
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

        return $approval->fresh(['requestedBy', 'approver', 'reviewedBy']);
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

        return $approval->fresh(['requestedBy', 'approver', 'reviewedBy']);
    }

    /**
     * @param  array{status?:string,payment_status?:string,company_id?:int}  $filters
     */
    public function listAllPackageOrders(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = PackageOrder::query()
            ->with(['package', 'user', 'company'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{status?:string}  $filters
     */
    public function listAllPayments(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Payment::query()
            ->with(['invoice'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * @return array<string, float|int>
     */
    public function getFinanceSummary(): array
    {
        $paidSum = DB::table('payments')->where('status', Payment::STATUS_PAID)->sum('amount');
        $accruedSum = DB::table('commission_records')->where('status', 'accrued')->sum('commission_amount_snapshot');
        $pendingSum = DB::table('commission_records')->where('status', 'pending')->sum('commission_amount_snapshot');

        return [
            'total_payments_paid' => (float) ($paidSum ?? 0),
            'total_commission_accrued' => (float) ($accruedSum ?? 0),
            'total_commission_pending' => (float) ($pendingSum ?? 0),
            'payments_count_paid' => (int) DB::table('payments')->where('status', Payment::STATUS_PAID)->count(),
            'commission_records_count' => (int) DB::table('commission_records')->count(),
        ];
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

        return $package->fresh(['offer', 'company']);
    }
}
