<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CompanyResource;
use App\Http\Resources\Api\CompanyUserResource;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Models\CompanySellerPermission;
use App\Services\Admin\AdminAccessService;
use App\Services\Companies\CompanyService;
use App\Services\Companies\SellerApplicationService;
use App\Services\Pdf\ContractPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    private const COMPANY_ROLE_NAMES = ['company_admin', 'company_operator', 'company_viewer'];

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
        ContractPdfService $contractService,
        AdminAccessService $adminAccessService
    ): Response {
        $user = $request->user();
        if (! $adminAccessService->isSuperAdmin($user) && ! $user->belongsToCompany((int) $company->id)) {
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
        Company $company,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $actor = $request->user();
        $companyId = (int) $company->id;

        $can = $adminAccessService->isSuperAdmin($actor) || $this->isCompanyAdmin($actor, $companyId);
        if (! $can) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
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
        User $user,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $actor = $request->user();
        $companyId = (int) $company->id;

        $can = $adminAccessService->isSuperAdmin($actor) || $this->isCompanyAdmin($actor, $companyId);
        if (! $can) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
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
            ->where('company_id', $companyId)
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
        User $user,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $actor = $request->user();
        $companyId = (int) $company->id;

        $can = $adminAccessService->isSuperAdmin($actor) || $this->isCompanyAdmin($actor, $companyId);
        if (! $can) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate self',
            ], 422);
        }

        $belongs = UserCompany::query()
            ->where('company_id', $companyId)
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

    private function isCompanyAdmin(User $user, int $companyId): bool
    {
        return UserCompany::query()
            ->where('company_id', $companyId)
            ->where('user_id', (int) $user->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'company_admin'))
            ->exists();
    }

    public function updateProfile(
        Request $request,
        Company $company,
        CompanyService $companyService,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $user = $request->user();
        $canEdit = $adminAccessService->isSuperAdmin($user)
            || ($user->belongsToCompany((int) $company->id)
                && $user->hasCompanyPermission((int) $company->id, 'companies.edit_profile'));

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
        CompanyService $companyService,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $user = $request->user();
        $canView = $adminAccessService->isSuperAdmin($user)
            || ($user->belongsToCompany((int) $company->id)
                && $user->hasCompanyPermission((int) $company->id, 'companies.view_dashboard'));

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
        CompanyService $companyService,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $user = $request->user();
        $canView = $adminAccessService->isSuperAdmin($user)
            || ($user->belongsToCompany((int) $company->id)
                && $user->hasCompanyPermission((int) $company->id, 'seller_permissions.view'));

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
        SellerApplicationService $service,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if (! $adminAccessService->isSuperAdmin($user) && ! $user->belongsToCompany((int) $company->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
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
        SellerApplicationService $service,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if (! $adminAccessService->isSuperAdmin($user) && ! $user->belongsToCompany((int) $company->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $applications = $service->listForCompany($company);

        return response()->json([
            'success' => true,
            'data' => $applications,
        ]);
    }

    public function setAirlineFlag(
        Request $request,
        Company $company,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        if (! $adminAccessService->isSuperAdmin($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
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
        CompanyService $companyService,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        if (! $adminAccessService->isSuperAdmin($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
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
        CompanyService $companyService,
        AdminAccessService $adminAccessService
    ): JsonResponse {
        if (! $adminAccessService->isSuperAdmin($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
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
}
