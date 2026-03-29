<?php

namespace App\Services\Companies;

use App\Models\Company;
use App\Models\CompanySellerPermission;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyService
{
    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function listForUser(User $user): EloquentCollection
    {
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return Company::query()
                ->whereIn('id', $this->adminAccessService->allCompanyIdsOrdered())
                ->orderBy('id')
                ->get();
        }

        $ids = [];
        foreach ($user->companies as $company) {
            if ($user->hasCompanyPermission((int) $company->id, 'companies.view')) {
                $ids[] = (int) $company->id;
            }
        }

        if ($ids === []) {
            return new EloquentCollection;
        }

        return Company::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    public function findForUser(User $user, Company $company): ?Company
    {
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return $company;
        }

        if (! $user->belongsToCompany((int) $company->id)) {
            return null;
        }

        if (! $user->hasCompanyPermission((int) $company->id, 'companies.view')) {
            return null;
        }

        return $company;
    }

    /**
     * @return EloquentCollection<int, UserCompany>|null
     */
    public function usersForCompany(User $user, Company $company): ?EloquentCollection
    {
        if ($this->findForUser($user, $company) === null) {
            return null;
        }

        return UserCompany::query()
            ->where('company_id', $company->id)
            ->with(['user', 'role'])
            ->orderBy('id')
            ->get();
    }

    public function updateProfile(Company $company, User $actor, array $data): Company
    {
        $allowed = [
            'name', 'legal_name', 'slug', 'tax_id', 'country', 'city', 'address',
            'phone', 'website', 'description', 'logo',
        ];
        $data = array_intersect_key($data, array_flip($allowed));

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('companies', 'slug')->ignore($company->id),
            ],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country' => ['sometimes', 'nullable', 'string', 'max:64'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'address' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website' => ['sometimes', 'nullable', 'string', 'max:512'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $validator->validate();

        $company->fill($validator->validated());
        $company->save();
        $company->refresh();

        if (
            $company->name !== null && $company->name !== ''
            && $company->country !== null && $company->country !== ''
            && $company->city !== null && $company->city !== ''
            && $company->address !== null && $company->address !== ''
        ) {
            if (! $company->profile_completed) {
                $company->profile_completed = true;
                $company->save();
                $company->refresh();
            }
        }

        return $company->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardSummary(Company $company): array
    {
        $companyId = (int) $company->id;

        $offersCount = (int) DB::table('offers')->where('company_id', $companyId)->count();
        $activeOffersCount = (int) DB::table('offers')
            ->where('company_id', $companyId)
            ->where('status', 'published')
            ->count();

        $packagesCount = (int) DB::table('packages')->where('company_id', $companyId)->count();
        $activePackagesCount = (int) DB::table('packages')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->count();

        $bookingsCount = (int) DB::table('bookings')->where('company_id', $companyId)->count();

        $packageOrdersCount = (int) DB::table('package_orders')->where('company_id', $companyId)->count();
        $paidPackageOrdersCount = (int) DB::table('package_orders')
            ->where('company_id', $companyId)
            ->where('payment_status', 'paid')
            ->count();
        $pendingPackageOrdersCount = (int) DB::table('package_orders')
            ->where('company_id', $companyId)
            ->where('status', 'pending_payment')
            ->count();

        $recentPackageOrderRows = DB::table('package_orders')
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get([
                'id',
                'order_number',
                'status',
                'payment_status',
                'final_total_snapshot',
                'currency',
                'created_at',
            ]);

        $recentBookingRows = DB::table('bookings')
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'status', 'total_price', 'created_at']);

        $sellerPermissionTypes = DB::table('company_seller_permissions')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('service_type')
            ->pluck('service_type')
            ->values()
            ->all();

        return [
            'company_id' => $companyId,
            'offers_count' => $offersCount,
            'active_offers_count' => $activeOffersCount,
            'packages_count' => $packagesCount,
            'active_packages_count' => $activePackagesCount,
            'bookings_count' => $bookingsCount,
            'package_orders_count' => $packageOrdersCount,
            'paid_package_orders_count' => $paidPackageOrdersCount,
            'pending_package_orders_count' => $pendingPackageOrdersCount,
            'recent_package_orders' => $recentPackageOrderRows->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'order_number' => $row->order_number,
                    'status' => $row->status,
                    'payment_status' => $row->payment_status,
                    'final_total_snapshot' => $row->final_total_snapshot,
                    'currency' => $row->currency,
                    'created_at' => $row->created_at !== null
                        ? (string) $row->created_at
                        : null,
                ];
            })->all(),
            'recent_bookings' => $recentBookingRows->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'status' => $row->status,
                    'total_price' => $row->total_price,
                    'created_at' => $row->created_at !== null
                        ? (string) $row->created_at
                        : null,
                ];
            })->all(),
            'seller_permissions' => $sellerPermissionTypes,
        ];
    }

    public function grantSellerPermission(Company $company, User $actor, string $serviceType): CompanySellerPermission
    {
        if (! in_array($serviceType, CompanySellerPermission::SERVICE_TYPES, true)) {
            throw ValidationException::withMessages([
                'service_type' => ['Invalid service type.'],
            ]);
        }

        $activeBefore = CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->count();

        $existing = CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->where('service_type', $serviceType)
            ->first();

        if ($existing !== null && $existing->status === 'active') {
            return $existing;
        }

        if ($existing !== null) {
            $existing->status = 'active';
            $existing->granted_by = $actor->id;
            $existing->granted_at = now();
            $existing->save();
            $permission = $existing->fresh();
        } else {
            $permission = CompanySellerPermission::query()->create([
                'company_id' => $company->id,
                'service_type' => $serviceType,
                'status' => 'active',
                'granted_by' => $actor->id,
                'granted_at' => now(),
            ]);
        }

        $activeAfter = CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->count();

        if ($activeBefore === 0 && $activeAfter > 0) {
            $company->is_seller = true;
            if ($company->seller_activated_at === null) {
                $company->seller_activated_at = now();
            }
            $company->save();
        }

        return $permission;
    }

    public function revokeSellerPermission(Company $company, string $serviceType): void
    {
        $permission = CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->where('service_type', $serviceType)
            ->first();

        if ($permission === null) {
            return;
        }

        $permission->status = 'revoked';
        $permission->save();

        $hasActive = CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->exists();

        if (! $hasActive) {
            $company->is_seller = false;
            $company->save();
        }
    }

    /**
     * Align active seller module permissions with the requested set (super-admin bulk / Blade parity).
     *
     * @param  list<string>  $requestedServiceTypes
     */
    public function syncActiveSellerPermissionServiceTypes(Company $company, User $actor, array $requestedServiceTypes): Company
    {
        $requestedServiceTypes = array_values(array_unique($requestedServiceTypes));

        $activePermissions = $company->sellerPermissions()
            ->where('status', 'active')
            ->pluck('service_type')
            ->all();

        foreach ($activePermissions as $serviceType) {
            if (! in_array($serviceType, $requestedServiceTypes, true)) {
                $this->revokeSellerPermission($company, $serviceType);
            }
        }

        foreach ($requestedServiceTypes as $serviceType) {
            if (! in_array($serviceType, $activePermissions, true)) {
                $this->grantSellerPermission($company, $actor, $serviceType);
            }
        }

        return $company->fresh() ?? $company;
    }

    /**
     * Flip company.is_seller (Blade admin toggle-seller parity).
     */
    public function toggleSellerEnabledFlag(Company $company): Company
    {
        $company->update(['is_seller' => ! $company->is_seller]);

        return $company->fresh() ?? $company;
    }

    /**
     * @return EloquentCollection<int, CompanySellerPermission>
     */
    public function getSellerPermissions(Company $company): EloquentCollection
    {
        return CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->orderBy('service_type')
            ->get();
    }

    public function canSellServiceType(Company $company, string $serviceType): bool
    {
        return CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->where('service_type', $serviceType)
            ->where('status', 'active')
            ->exists();
    }
}
