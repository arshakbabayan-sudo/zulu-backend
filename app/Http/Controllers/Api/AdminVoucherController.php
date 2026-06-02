<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Services\Admin\AdminAccessService;
use App\Services\Vouchers\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVoucherController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private VoucherService $voucherService,
    ) {}

    /**
     * GET /api/platform-admin/vouchers
     * List vouchers with optional filters: status, service_type, q (number/holder).
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = Voucher::query()
            ->with(['order:id,order_number,user_id', 'issuerCompany:id,name'])
            ->orderByDesc('created_at');

        // Tenant scope: non-super callers see only vouchers their own company
        // issued. Empty company list → [0] → no rows (never a leak).
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $query->whereIn('issuer_company_id', $this->adminAccessService->visibleCompanyIds($user) ?: [0]);
        }

        if (is_string($status = $request->query('status')) && in_array($status, Voucher::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (is_string($serviceType = $request->query('service_type')) && in_array($serviceType, Voucher::SERVICE_TYPES, true)) {
            $query->where('service_type', $serviceType);
        }

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $term = trim($q);
            $query->where(function ($qb) use ($term): void {
                $qb->where('voucher_number', 'like', "%{$term}%")
                    ->orWhere('holder_name', 'like', "%{$term}%");
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/platform-admin/vouchers/{voucher}
     * Voucher detail + last 50 verification log entries.
     */
    public function show(Request $request, string $voucher): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = Voucher::query()
            ->with(['order:id,order_number,user_id,company_id,total,currency', 'orderItem:id,item_type,date_from,date_to', 'issuerCompany:id,name'])
            ->whereKey($voucher)
            ->first();

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Voucher not found'], 404);
        }

        $logs = $model->verificationLogs()
            ->orderByDesc('scanned_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'voucher' => $model,
                'verification_logs' => $logs,
            ],
        ]);
    }

    /**
     * Finance group v2 — Phase 2e (Item 4.1).
     *
     * POST /api/platform-admin/vouchers
     *
     * Admin-side manual voucher issue. Used for compensation, gifts, and
     * one-off cases outside the normal booking flow. Generates a voucher
     * with `ADM-` prefix and status='issued'.
     */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'service_type' => ['required', 'string', 'in:'.implode(',', Voucher::SERVICE_TYPES)],
            'holder_name' => ['required', 'string', 'max:255'],
            'holder_passport' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'in:hy,en,ru'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'issuer_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $voucher = $this->voucherService->createManual($validated, $request->user());

        return response()->json([
            'success' => true,
            'data' => $voucher,
        ], 201);
    }

    /**
     * POST /api/platform-admin/vouchers/{voucher}/void
     * Marks the voucher void (e.g., after refund / cancellation).
     */
    public function void(Request $request, string $voucher): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = Voucher::query()->whereKey($voucher)->first();
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Voucher not found'], 404);
        }

        if (in_array($model->status, ['void', 'reissued'], true)) {
            return response()->json(['success' => false, 'message' => "Voucher is already {$model->status}"], 422);
        }

        $voided = $this->voucherService->void($model);

        return response()->json([
            'success' => true,
            'data' => $voided,
        ]);
    }

    /**
     * POST /api/platform-admin/vouchers/{voucher}/reissue
     * Reissue with optional name correction; original goes to status 'reissued'.
     */
    public function reissue(Request $request, string $voucher): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = Voucher::query()->whereKey($voucher)->first();
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Voucher not found'], 404);
        }

        if (in_array($model->status, ['void', 'reissued'], true)) {
            return response()->json(['success' => false, 'message' => "Cannot reissue a {$model->status} voucher"], 422);
        }

        $overrides = $request->validate([
            'holder_name' => 'sometimes|string|max:255',
            'holder_passport' => 'sometimes|nullable|string|max:64',
            'language' => 'sometimes|in:hy,en,ru',
        ]);

        $reissued = $this->voucherService->reissue($model, $overrides);

        return response()->json([
            'success' => true,
            'data' => $reissued,
        ]);
    }

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->canAccessAdminPanel($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }
}
