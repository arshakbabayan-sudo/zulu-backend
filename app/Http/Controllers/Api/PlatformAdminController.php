<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApprovalResource;
use App\Http\Resources\Api\CompanyResource;
use App\Http\Resources\Api\PackageOrderResource;
use App\Http\Resources\Api\PackageResource;
use App\Models\Approval;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\CompanySellerApplication;
use App\Models\CompanySellerPermission;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Review;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\PlatformAdminService;
use App\Services\Approvals\CompanyApplicationApprovalService;
use App\Services\Companies\CompanyService;
use App\Services\Companies\SellerApplicationService;
use App\Services\Infrastructure\PlatformSettingsService;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class PlatformAdminController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService,
        private CompanyService $companyService,
    ) {}

    public function stats(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'success' => true,
            'data' => $service->getPlatformStats(),
        ]);
    }

    public function companies(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = [
            'governance_status' => $request->filled('governance_status') ? (string) $request->query('governance_status') : null,
            'is_seller' => $this->parseOptionalBool($request->query('is_seller')),
            'search' => $request->filled('search') ? (string) $request->query('search') : null,
            'type' => $request->filled('type') ? (string) $request->query('type') : null,
        ];

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listCompanies(array_filter(
            $filters,
            static fn ($v) => $v !== null && $v !== ''
        ), $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, CompanyResource::class);
    }

    public function changeGovernance(Request $request, Company $company, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'governance_status' => ['required', 'string', Rule::in(Company::GOVERNANCE_STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $service->changeCompanyGovernanceStatus(
            $company,
            $request->user(),
            $validated['governance_status'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => CompanyResource::make($updated)->toArray($request),
        ]);
    }

    /**
     * Bulk sync active seller module permissions (parity with PATCH admin/companies/{company}/permissions).
     */
    public function updateCompanyPermissions(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(CompanySellerPermission::SERVICE_TYPES)],
        ]);

        $requested = array_values(array_unique($validated['permissions'] ?? []));
        $updatedCompany = $this->companyService->syncActiveSellerPermissionServiceTypes(
            $company,
            $request->user(),
            $requested
        );

        $activePermissions = $updatedCompany->sellerPermissions()
            ->where('status', 'active')
            ->pluck('service_type')
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Company permissions updated successfully',
            'data' => [
                'company' => CompanyResource::make($updatedCompany)->toArray($request),
                'active_permissions' => $activePermissions,
            ],
        ]);
    }

    /**
     * Toggle company.is_seller (parity with PATCH admin/companies/{company}/toggle-seller).
     */
    public function toggleCompanySellerStatus(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $updated = $this->companyService->toggleSellerEnabledFlag($company);

        return response()->json([
            'success' => true,
            'message' => 'Seller status updated',
            'data' => [
                'is_seller' => (bool) $updated->is_seller,
                'company' => CompanyResource::make($updated)->toArray($request),
            ],
        ]);
    }

    public function approvals(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'entity_type' => $request->filled('entity_type') ? (string) $request->query('entity_type') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listApprovals($filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, ApprovalResource::class);
    }

    public function approveApproval(Request $request, Approval $approval, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fresh = $service->approveApproval(
            $approval,
            $request->user(),
            $validated['decision_notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => ApprovalResource::make($fresh)->toArray($request),
        ]);
    }

    public function rejectApproval(Request $request, Approval $approval, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fresh = $service->rejectApproval(
            $approval,
            $request->user(),
            $validated['decision_notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => ApprovalResource::make($fresh)->toArray($request),
        ]);
    }

    public function packageOrders(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'payment_status' => $request->filled('payment_status') ? (string) $request->query('payment_status') : null,
            'company_id' => $request->filled('company_id') ? (int) $request->query('company_id') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listAllPackageOrders($filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, PackageOrderResource::class);
    }

    public function payments(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listAllPayments($filters, $perPage);

        return $this->paginatedPaymentsResponse($request, $paginator);
    }

    public function financeSummary(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'success' => true,
            'data' => $service->getFinanceSummary(),
        ]);
    }

    public function packages(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'company_id' => $request->filled('company_id') ? (int) $request->query('company_id') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listAllPackages($filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, PackageResource::class);
    }

    // ─── User Management ────────────────────────────────────────────

    public function listUsers(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $perPage = $this->commerceListPerPage($request);
        $query = User::query()->with('companies')->orderByDesc('id');

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(fn (User $user): array => $this->platformAdminUserRow($user))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function deactivateUser(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $user = User::query()->findOrFail($id);

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate yourself.',
            ], 422);
        }

        $user->status = 'inactive';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User deactivated.',
            'data' => [
                'message' => 'User deactivated.',
                'user' => $this->platformAdminUserRow($user->load('companies')),
            ],
        ]);
    }

    // ─── Seller Applications ──────────────────────────────────────

    public function listSellerApplications(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $status = $request->filled('status') ? (string) $request->query('status') : null;

        $query = CompanySellerApplication::query()->with('company')->orderByDesc('id');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', [
                CompanySellerApplication::STATUS_PENDING,
                CompanySellerApplication::STATUS_UNDER_REVIEW,
            ]);
        }

        $paginator = $query->paginate($this->commerceListPerPage($request));

        $data = $paginator->getCollection()->map(fn (CompanySellerApplication $a): array => $this->sellerApplicationToAdminRow($a))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function approveSellerApplication(Request $request, int $id, SellerApplicationService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $application = CompanySellerApplication::query()->findOrFail($id);
        $fresh = $service->approve($application, $request->user()->id, $validated['notes'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Application approved.',
            'data' => [
                'message' => 'Application approved.',
                'application' => $this->sellerApplicationToAdminRow($fresh->loadMissing('company')),
            ],
        ]);
    }

    public function rejectSellerApplication(Request $request, int $id, SellerApplicationService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $application = CompanySellerApplication::query()->findOrFail($id);
        $fresh = $service->reject($application, $request->user()->id, $validated['rejection_reason']);

        return response()->json([
            'success' => true,
            'message' => 'Application rejected.',
            'data' => [
                'message' => 'Application rejected.',
                'application' => $this->sellerApplicationToAdminRow($fresh->loadMissing('company')),
            ],
        ]);
    }

    public function deactivatePackage(Request $request, Package $package, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $fresh = $service->forceDeactivatePackage(
            $package,
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => PackageResource::make($fresh)->toArray($request),
        ]);
    }

    public function approveApplication(
        Request $request,
        int $id,
        CompanyApplicationApprovalService $companyApplicationApprovalService
    ): JsonResponse {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $application = CompanyApplication::query()->findOrFail($id);
        $result = $companyApplicationApprovalService->approve(
            $application,
            $request->user(),
            $validated['decision_notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Application approved. Company and user created.',
            'data' => [
                'company_id' => $result['company']->id,
                'user_id' => $result['user']->id,
                'message' => 'Application approved. Company and user created.',
            ],
        ]);
    }

    public function rejectApplication(
        Request $request,
        int $id,
        CompanyApplicationApprovalService $companyApplicationApprovalService
    ): JsonResponse {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $application = CompanyApplication::query()->findOrFail($id);
        $companyApplicationApprovalService->reject($application, $request->user(), $validated['rejection_reason']);

        return response()->json([
            'success' => true,
            'message' => 'Application rejected.',
            'data' => ['message' => 'Application rejected.'],
        ]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'success' => true,
            'data' => PlatformSetting::query()->orderBy('key')->get()->map(fn (PlatformSetting $s): array => $this->platformSettingToAdminRow($s))->values()->all(),
        ]);
    }

    public function updateSetting(Request $request, string $key, PlatformSettingsService $platformSettingsService): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'value' => ['required', 'string', 'max:500'],
        ]);

        $existing = PlatformSetting::query()->where('key', $key)->first();
        if ($existing === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $platformSettingsService->set($key, $validated['value']);

        $updated = PlatformSetting::query()->where('key', $key)->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Setting updated.',
            'data' => $this->platformSettingToAdminRow($updated),
        ]);
    }

    public function listAllReviews(Request $request, ReviewService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Review::STATUSES)],
            'entity_type' => ['nullable', 'string', Rule::in(Review::TARGET_ENTITY_TYPES)],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = max(1, min((int) ($validated['per_page'] ?? 20), 100));
        $filters = [];
        if (! empty($validated['status'])) {
            $filters['status'] = $validated['status'];
        }
        if (! empty($validated['entity_type'])) {
            $filters['entity_type'] = $validated['entity_type'];
        }
        if ($request->filled('user_id')) {
            $filters['user_id'] = (int) $validated['user_id'];
        }

        $paginator = $service->listAllForAdmin($filters, $perPage);

        $data = $paginator->getCollection()->map(fn (Review $review): array => $this->platformAdminReviewRow($review))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function platformAdminUserRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'companies' => $user->companies->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'role' => $c->pivot->role_id
                    ? (Role::find($c->pivot->role_id)?->name ?? 'unknown')
                    : 'unknown',
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sellerApplicationToAdminRow(CompanySellerApplication $a): array
    {
        return [
            'id' => $a->id,
            'company_id' => $a->company_id,
            'company_name' => $a->company?->name,
            'service_type' => $a->service_type,
            'status' => $a->status,
            'rejection_reason' => $a->rejection_reason,
            'notes' => $a->notes,
            'applied_at' => $a->applied_at?->toIso8601String(),
            'reviewed_at' => $a->reviewed_at?->toIso8601String(),
            'reviewed_by' => $a->reviewed_by,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformSettingToAdminRow(PlatformSetting $s): array
    {
        return [
            'id' => $s->id,
            'key' => $s->key,
            'value' => $s->value,
            'type' => $s->type,
            'description' => $s->description,
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformAdminReviewRow(Review $review): array
    {
        $row = [
            'id' => $review->id,
            'rating' => $review->rating,
            'review_text' => $review->review_text,
            'status' => $review->status,
            'target_entity_type' => $review->target_entity_type,
            'target_entity_id' => $review->target_entity_id,
            'moderation_notes' => $review->moderation_notes,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
        if ($review->relationLoaded('user') && $review->user !== null) {
            $row['user'] = ['id' => $review->user->id, 'name' => $review->user->name];
        }

        return $row;
    }

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function parseOptionalBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = strtolower((string) $value);
        if (in_array($v, ['1', 'true'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false'], true)) {
            return false;
        }

        return null;
    }

    private function paginatedPaymentsResponse(Request $request, LengthAwarePaginator $paginator): JsonResponse
    {
        $data = $paginator->getCollection()->map(function (Payment $payment): array {
            $row = [
                'id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'payment_method' => $payment->payment_method,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'reference_code' => $payment->reference_code,
                'created_at' => $payment->created_at?->toIso8601String(),
            ];
            if ($payment->relationLoaded('invoice') && $payment->invoice !== null) {
                $row['invoice'] = [
                    'id' => $payment->invoice->id,
                    'total_amount' => (float) $payment->invoice->total_amount,
                    'status' => $payment->invoice->status,
                    'unique_booking_reference' => $payment->invoice->unique_booking_reference,
                ];
            }

            return $row;
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }
}
