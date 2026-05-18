<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CompanyResource;
use App\Http\Resources\Api\CompanyUserResource;
use App\Models\Company;
use App\Models\CompanyCountryPermission;
use App\Models\CompanyModulePermission;
use App\Models\CompanySellerPermission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use App\Services\Companies\CompanyService;
use App\Services\Companies\SellerApplicationService;
use App\Services\Pdf\ContractPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CompanyController extends Controller
{
    private const COMPANY_ROLE_NAMES = ['company_admin', 'operator_admin', 'company_operator', 'company_viewer'];

    public function __construct(
        private CompanyAccessService $companyAccessService,
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, CompanyService $companyService): JsonResponse
    {
        $companies = $companyService->listForUser($request->user());

        return response()->json([
            'success' => true,
            'data' => CompanyResource::collection($companies)->resolve(),
        ]);
    }

    public function show(Request $request, Company $company, CompanyService $companyService): JsonResponse
    {
        $found = $companyService->findForUser($request->user(), $company);

        if ($found === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => CompanyResource::make($found)->toArray($request),
        ]);
    }

    public function downloadContract(
        Request $request,
        Company $company,
        ContractPdfService $contractService
    ): Response {
        $user = $request->user();
        if (! $this->companyAccessService->canManageCompany($user, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $company->loadMissing('sellerPermissions');
        $serviceTypes = $company->sellerPermissions->pluck('service_type')->toArray();

        try {
            return $contractService->generate($company, $serviceTypes);
        } catch (\Throwable $e) {
            Log::warning('Contract PDF generation failed', ['error' => $e->getMessage(), 'company_id' => $company->id]);

            return response()->json([
                'success' => false,
                'message' => 'PDF generation failed',
            ], 500);
        }
    }

    public function users(Request $request, Company $company, CompanyService $companyService): JsonResponse
    {
        $memberships = $companyService->usersForCompany($request->user(), $company);

        if ($memberships === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => CompanyUserResource::collection($memberships)->resolve(),
        ]);
    }

    public function addUser(
        Request $request,
        Company $company
    ): JsonResponse {
        if ($deny = $this->denyUnlessCanManageCompany($request, $company)) {
            return $deny;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role_name' => ['required', 'string', Rule::in(self::COMPANY_ROLE_NAMES)],
        ]);

        $role = Role::query()->where('name', $validated['role_name'])->first();
        if ($role === null) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
            ], 422);
        }

        $user = DB::transaction(function () use ($validated, $company, $role): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'status' => User::STATUS_ACTIVE,
            ]);

            UserCompany::query()->create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role_id' => $role->id,
            ]);

            return $user->fresh();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                ],
            ],
        ], 201);
    }

    public function updateUserRole(
        Request $request,
        Company $company,
        User $user
    ): JsonResponse {
        if ($deny = $this->denyUnlessCanManageCompany($request, $company)) {
            return $deny;
        }

        $validated = $request->validate([
            'role_name' => ['required', 'string', Rule::in(self::COMPANY_ROLE_NAMES)],
        ]);

        $role = Role::query()->where('name', $validated['role_name'])->first();
        if ($role === null) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
            ], 422);
        }

        $membership = UserCompany::query()
            ->where('company_id', (int) $company->id)
            ->where('user_id', (int) $user->id)
            ->first();

        if ($membership === null) {
            return response()->json([
                'success' => false,
                'message' => 'User not found in company',
            ], 404);
        }

        $membership->role_id = $role->id;
        $membership->save();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Role updated.',
            ],
        ]);
    }

    public function deactivateUser(
        Request $request,
        Company $company,
        User $user
    ): JsonResponse {
        if ($deny = $this->denyUnlessCanManageCompany($request, $company)) {
            return $deny;
        }
        $actor = $request->user();

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate self',
            ], 422);
        }

        $belongs = UserCompany::query()
            ->where('company_id', (int) $company->id)
            ->where('user_id', (int) $user->id)
            ->exists();

        if (! $belongs) {
            return response()->json([
                'success' => false,
                'message' => 'User not found in company',
            ], 404);
        }

        $user->status = 'inactive';
        $user->save();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'User deactivated.',
            ],
        ]);
    }

    public function updateProfile(
        Request $request,
        Company $company,
        CompanyService $companyService
    ): JsonResponse {
        $user = $request->user();
        $canEdit = $this->companyAccessService->canAccessCompany($user, $company, 'companies.edit_profile');

        if (! $canEdit) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $updated = $companyService->updateProfile($company, $user, $request->all());

        return response()->json([
            'success' => true,
            'data' => CompanyResource::make($updated)->toArray($request),
        ]);
    }

    public function dashboard(
        Request $request,
        Company $company,
        CompanyService $companyService
    ): JsonResponse {
        $user = $request->user();
        $canView = $this->companyAccessService->canAccessCompany($user, $company, 'companies.view_dashboard');

        if (! $canView) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $summary = $companyService->getDashboardSummary($company);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    public function sellerPermissions(
        Request $request,
        Company $company,
        CompanyService $companyService
    ): JsonResponse {
        $user = $request->user();
        $canView = $this->companyAccessService->canAccessCompany($user, $company, 'seller_permissions.view');

        if (! $canView) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $permissions = $companyService->getSellerPermissions($company);
        $permissionRows = $permissions->map(fn (CompanySellerPermission $p): array => [
            'id' => $p->id,
            'service_type' => $p->service_type,
            'status' => $p->status,
            'granted_at' => $p->granted_at?->toIso8601String(),
            'notes' => $p->notes,
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => $permissionRows,
                'applications' => $company->sellerApplications()
                    ->orderByDesc('applied_at')
                    ->get()
                    ->map(fn ($a) => [
                        'service_type' => $a->service_type,
                        'status' => $a->status,
                        'applied_at' => $a->applied_at?->toIso8601String(),
                        'rejection_reason' => $a->rejection_reason,
                    ]),
            ],
        ]);
    }

    public function submitSellerApplication(
        Request $request,
        Company $company,
        SellerApplicationService $service
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($deny = $this->denyUnlessCanManageCompany($request, $company)) {
            return $deny;
        }

        $validated = $request->validate([
            'service_type' => ['required', 'string', Rule::in(CompanySellerPermission::SERVICE_TYPES)],
        ]);

        $application = $service->applyForService($company, $validated['service_type'], $user->id);

        return response()->json([
            'success' => true,
            'data' => [
                'application' => $application,
            ],
        ]);
    }

    public function listSellerApplications(
        Request $request,
        Company $company,
        SellerApplicationService $service
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($deny = $this->denyUnlessCanManageCompany($request, $company)) {
            return $deny;
        }

        $applications = $service->listForCompany($company);

        return response()->json([
            'success' => true,
            'data' => $applications,
        ]);
    }

    public function setAirlineFlag(
        Request $request,
        Company $company
    ): JsonResponse {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'is_airline' => ['required', 'boolean'],
        ]);

        $company->is_airline = (bool) $validated['is_airline'];
        $company->save();

        return response()->json([
            'success' => true,
            'data' => CompanyResource::make($company->fresh())->toArray($request),
        ]);
    }

    public function grantSellerPermission(
        Request $request,
        Company $company,
        CompanyService $companyService
    ): JsonResponse {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = Validator::make($request->all(), [
            'service_type' => ['required', 'string', Rule::in(CompanySellerPermission::SERVICE_TYPES)],
        ])->validate();

        $permission = $companyService->grantSellerPermission(
            $company,
            $request->user(),
            $validated['service_type']
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $permission->id,
                'service_type' => $permission->service_type,
                'status' => $permission->status,
                'granted_at' => $permission->granted_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function revokeSellerPermission(
        Request $request,
        Company $company,
        string $serviceType,
        CompanyService $companyService
    ): JsonResponse {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        Validator::make(
            ['service_type' => $serviceType],
            ['service_type' => ['required', 'string', Rule::in(CompanySellerPermission::SERVICE_TYPES)]],
        )->validate();

        $companyService->revokeSellerPermission($company, $serviceType);

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Country permissions (multi-country seller licenses)
    // ──────────────────────────────────────────────────────────────────

    public function countryPermissions(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        $canView = $this->companyAccessService->canAccessCompany($user, $company, 'seller_permissions.view');
        if (! $canView) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $rows = CompanyCountryPermission::query()
            ->where('company_id', $company->id)
            ->orderBy('country_name')
            ->get()
            ->map(fn (CompanyCountryPermission $p) => [
                'id' => $p->id,
                'country_code' => $p->country_code,
                'country_name' => $p->country_name,
                'status' => $p->status,
                'granted_at' => $p->granted_at?->toIso8601String(),
                'notes' => $p->notes,
            ])
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                // Always include the company's home country as an implicit grant
                // so the UI shows it as the always-allowed baseline.
                'home_country' => $company->country,
                'permissions' => $rows,
            ],
        ]);
    }

    public function syncCountryPermissions(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'countries' => ['required', 'array'],
            'countries.*.country_code' => ['required', 'string', 'max:8'],
            'countries.*.country_name' => ['required', 'string', 'max:64'],
        ]);

        $now = now();
        $userId = $request->user()->id;

        // Set the desired set; revoke anything not in it.
        $desiredCodes = [];
        foreach ($validated['countries'] as $row) {
            $code = strtoupper(trim($row['country_code']));
            $name = trim($row['country_name']);
            $desiredCodes[] = $code;

            CompanyCountryPermission::query()->updateOrCreate(
                ['company_id' => $company->id, 'country_code' => $code],
                [
                    'country_name' => $name,
                    'status' => CompanyCountryPermission::STATUS_ACTIVE,
                    'granted_by' => $userId,
                    'granted_at' => $now,
                ]
            );
        }

        // Revoke any active rows not in the desired set.
        CompanyCountryPermission::query()
            ->where('company_id', $company->id)
            ->where('status', CompanyCountryPermission::STATUS_ACTIVE)
            ->when(! empty($desiredCodes), fn ($q) => $q->whereNotIn('country_code', $desiredCodes))
            ->update(['status' => CompanyCountryPermission::STATUS_REVOKED]);

        return $this->countryPermissions($request, $company);
    }

    // ──────────────────────────────────────────────────────────────────
    // Module permissions (admin sidebar gating per company — Phase 6A)
    // ──────────────────────────────────────────────────────────────────

    /**
     * GET /platform-admin/companies/{company}/module-permissions
     * Returns the explicit allow/deny rows + the canonical list of module
     * keys so the UI can render a checkbox grid even for companies that have
     * no rows yet (default state: every module allowed).
     */
    public function modulePermissions(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $rows = CompanyModulePermission::query()
            ->where('company_id', $company->id)
            ->get()
            ->map(fn (CompanyModulePermission $p) => [
                'module_key' => $p->module_key,
                'is_allowed' => (bool) $p->is_allowed,
                'granted_at' => $p->granted_at?->toIso8601String(),
                'notes' => $p->notes,
            ])
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $company->id,
                'available_module_keys' => CompanyModulePermission::MODULE_KEYS,
                'permissions' => $rows,
            ],
        ]);
    }

    /**
     * PATCH /platform-admin/companies/{company}/module-permissions
     * Accepts a sparse map of explicit settings. Modules omitted from the
     * payload keep their existing row (or remain in default-allow state).
     */
    public function patchModulePermissions(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*.module_key' => ['required', 'string', 'max:64'],
            'permissions.*.is_allowed' => ['required', 'boolean'],
            'permissions.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $now = now();
        $userId = $request->user()->id;

        foreach ($validated['permissions'] as $row) {
            CompanyModulePermission::query()->updateOrCreate(
                ['company_id' => $company->id, 'module_key' => $row['module_key']],
                [
                    'is_allowed' => $row['is_allowed'],
                    'granted_by_user_id' => $userId,
                    'granted_at' => $now,
                    'notes' => $row['notes'] ?? null,
                ]
            );
        }

        return $this->modulePermissions($request, $company);
    }

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
    }

    private function denyUnlessCanManageCompany(Request $request, Company $company): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->companyAccessService->canManageCompany($user, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
    }
}
