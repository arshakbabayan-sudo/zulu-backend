<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SellerFxRate;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use App\Services\Pricing\SellerFxRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * B2a — admin CRUD for the "seller FX-rate" SETTING (seller_fx_rates).
 *
 * Two distinct write surfaces (mirrors the house split between
 * OperatorCommissionController and ExchangeRateController/MoneyFlowTermController):
 *
 * (1) OPERATOR / AGENT self-service — manages ONLY the caller's OWN company's
 *     row. The company id is PINNED from the authenticated membership, never
 *     trusted from the request body, so a member of company A can never write
 *     company B's setting. The write gate is in-controller + AGENT-AWARE
 *     (canManageSellerStatus) because an agency owner's role is `agent`, which
 *     holds only `.view` permissions — a `.manage` permission-middleware would
 *     403 them. Lives in the Sanctum group; reads gated `permission:commissions.view`
 *     (super/platform bypass; operator owner + agent both hold .view).
 *       GET  /api/operator/seller-fx-rate
 *       PUT  /api/operator/seller-fx-rate
 *
 * (2) SUPER-ADMIN platform / any-scope — manages the PLATFORM default
 *     (scope=platform, company_id null) OR any operator/agent row (body scope +
 *     company_id trusted, because super only). Lives under the platform-admin
 *     route group (EnsurePlatformAdmin blocks operators from any POST/PUT
 *     there) with an in-controller `is_super_admin` guard as defence-in-depth.
 *       GET  /api/platform-admin/seller-fx-rates
 *       POST /api/platform-admin/seller-fx-rates
 *       PUT  /api/platform-admin/seller-fx-rates/{sellerFxRate}
 *
 * Validation (server-side): source in [cba,manual]; margin numeric >= 0
 * (ABSOLUTE per-unit add); target_currency optional default AMD, upper-cased,
 * allow-list USD/EUR/AMD; if source=manual then manual_rates required with at
 * least one CUR (allow-list) → positive number. Persists via
 * SellerFxRateService::upsertSetting (unique key scope+company_id+target).
 */
class SellerFxRateController extends Controller
{
    /** Currencies a seller FX setting may target / quote a manual rate for. */
    private const ALLOWED_CURRENCIES = ['USD', 'EUR', 'AMD'];

    public function __construct(
        private readonly SellerFxRateService $fxRateService,
        private readonly AdminAccessService $adminAccessService,
        private readonly CompanyAccessService $companyAccessService,
    ) {}

    // ── (1) Operator / agent self-service ────────────────────────────────

    /**
     * GET /api/operator/seller-fx-rate
     *
     * Operator/agent: returns their OWN setting (or null) for the requested
     * target currency. Super/platform-admin may inspect a specific row via
     * ?scope=&company_id=&target_currency= (defaults to the platform default).
     */
    public function showOwn(Request $request): JsonResponse
    {
        $user = $request->user();
        $target = $this->resolveTargetCurrency($request->query('target_currency'));

        // Super/platform-admin: read any explicitly-addressed row.
        if ($user !== null && $this->adminAccessService->isPlatformAdmin($user)) {
            $scope = (string) $request->query('scope', SellerFxRate::SCOPE_PLATFORM);
            $companyId = $request->query('company_id') !== null
                ? (int) $request->query('company_id')
                : null;

            $row = SellerFxRate::query()
                ->where('scope', $scope)
                ->where('target_currency', $target)
                ->when(
                    $scope === SellerFxRate::SCOPE_PLATFORM,
                    fn ($q) => $q->whereNull('company_id'),
                    fn ($q) => $q->where('company_id', $companyId),
                )
                ->first();

            return response()->json([
                'success' => true,
                'data' => $row !== null ? $this->serialize($row) : null,
            ]);
        }

        $company = $this->resolveOwnCompany($request);
        if ($company === null) {
            return response()->json([
                'success' => false,
                'message' => 'No active company on this user.',
            ], 404);
        }

        $scope = $this->scopeForCompany($company);
        if ($scope === null) {
            return response()->json([
                'success' => false,
                'message' => 'FX setting is not available for this company type.',
            ], 422);
        }

        $row = SellerFxRate::query()
            ->where('scope', $scope)
            ->where('company_id', $company->id)
            ->where('target_currency', $target)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $row !== null ? $this->serialize($row) : null,
        ]);
    }

    /**
     * PUT /api/operator/seller-fx-rate
     *
     * Upsert the CALLER's OWN setting. company_id + scope are derived from the
     * authenticated membership — the body NEVER supplies them for a non-super
     * caller, guaranteeing tenant isolation.
     */
    public function upsertOwn(Request $request): JsonResponse
    {
        $company = $this->resolveOwnCompany($request);
        if ($company === null) {
            return response()->json([
                'success' => false,
                'message' => 'No active company on this user.',
            ], 404);
        }

        if ($deny = $this->denyUnlessCanManageFx($request, $company)) {
            return $deny;
        }

        $scope = $this->scopeForCompany($company);
        if ($scope === null) {
            return response()->json([
                'success' => false,
                'message' => 'FX setting is not available for this company type.',
            ], 422);
        }

        $validated = $this->validatePayload($request);

        $row = $this->fxRateService->upsertSetting(
            $scope,
            $company->id,
            $validated['target_currency'],
            $validated,
        );

        return response()->json(['success' => true, 'data' => $this->serialize($row)]);
    }

    // ── (2) Super-admin platform / any-scope ─────────────────────────────

    /**
     * GET /api/platform-admin/seller-fx-rates
     *
     * Super-admin: list all settings (optional ?scope= / ?company_id= /
     * ?target_currency= filters).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $query = SellerFxRate::query()->with('company:id,name,type');

        if ($scope = $request->query('scope')) {
            $query->where('scope', $scope);
        }
        if (($companyId = $request->query('company_id')) !== null && $companyId !== '') {
            $query->where('company_id', (int) $companyId);
        }
        if ($target = $request->query('target_currency')) {
            $query->where('target_currency', strtoupper((string) $target));
        }

        $rows = $query->orderBy('scope')->orderBy('company_id')->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn (SellerFxRate $r) => $this->serialize($r))->values()->all(),
        ]);
    }

    /**
     * POST /api/platform-admin/seller-fx-rates
     *
     * Super-admin: upsert the platform default (scope=platform, company_id
     * null) OR any operator/agent row (scope + company_id trusted from the
     * body, because super only).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden — super-admin only'], 403);
        }

        $scopeData = $request->validate([
            'scope' => ['required', Rule::in([
                SellerFxRate::SCOPE_PLATFORM,
                SellerFxRate::SCOPE_OPERATOR,
                SellerFxRate::SCOPE_AGENT,
            ])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $scope = $scopeData['scope'];
        $companyId = $scopeData['company_id'] ?? null;

        if ($scope === SellerFxRate::SCOPE_PLATFORM) {
            // Platform default is a singleton — never company-bound.
            $companyId = null;
        } elseif ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'company_id is required for operator/agent scope.',
            ], 422);
        }

        $validated = $this->validatePayload($request);

        $row = $this->fxRateService->upsertSetting(
            $scope,
            $companyId,
            $validated['target_currency'],
            $validated,
        );

        return response()->json(['success' => true, 'data' => $this->serialize($row->fresh('company'))], 201);
    }

    /**
     * PUT /api/platform-admin/seller-fx-rates/{sellerFxRate}
     *
     * Super-admin: update an existing row in place (scope/company are taken
     * from the bound row, never re-keyed).
     */
    public function update(Request $request, SellerFxRate $sellerFxRate): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden — super-admin only'], 403);
        }

        $validated = $this->validatePayload($request, $sellerFxRate->target_currency);

        $row = $this->fxRateService->upsertSetting(
            $sellerFxRate->scope,
            $sellerFxRate->company_id !== null ? (int) $sellerFxRate->company_id : null,
            $validated['target_currency'],
            $validated,
        );

        return response()->json(['success' => true, 'data' => $this->serialize($row->fresh('company'))]);
    }

    // ── Shared helpers ───────────────────────────────────────────────────

    /**
     * Server-side validation shared by every write path. Returns the
     * normalized payload (upper-cased currencies; manual_rates only when
     * source=manual). $defaultTarget seeds the target currency for the
     * super-admin update path (where the row already has one).
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?string $defaultTarget = null): array
    {
        $validated = $request->validate([
            'source' => ['required', Rule::in([SellerFxRate::SOURCE_CBA, SellerFxRate::SOURCE_MANUAL])],
            'margin' => ['required', 'numeric', 'min:0'],
            'target_currency' => ['sometimes', 'string', 'size:3', Rule::in(self::ALLOWED_CURRENCIES)],
            'is_active' => ['sometimes', 'boolean'],
            // manual_rates required (>=1 entry) when source=manual, else ignored.
            'manual_rates' => ['required_if:source,'.SellerFxRate::SOURCE_MANUAL, 'array', 'min:1'],
            'manual_rates.*' => ['numeric', 'gt:0'],
        ]);

        // Currency-key allow-list for manual_rates (each key uppercased + allowed).
        // $request->validate() can constrain VALUES (manual_rates.*) but not the
        // KEYS, so the per-currency allow-list is enforced here.
        if (($validated['source'] ?? null) === SellerFxRate::SOURCE_MANUAL) {
            $normalized = [];
            foreach ((array) ($validated['manual_rates'] ?? []) as $cur => $rate) {
                $upper = strtoupper((string) $cur);
                if (! in_array($upper, self::ALLOWED_CURRENCIES, true)) {
                    throw ValidationException::withMessages([
                        'manual_rates' => ["Unsupported currency: {$cur}. Allowed: ".implode(', ', self::ALLOWED_CURRENCIES)],
                    ]);
                }
                $normalized[$upper] = $rate;
            }
            if ($normalized === []) {
                throw ValidationException::withMessages([
                    'manual_rates' => ['At least one rate is required for manual source.'],
                ]);
            }
            $validated['manual_rates'] = $normalized;
        } else {
            unset($validated['manual_rates']);
        }

        $validated['target_currency'] = $this->resolveTargetCurrency(
            $validated['target_currency'] ?? $defaultTarget
        );

        return $validated;
    }

    /** Normalize the target currency: upper-cased, default AMD. */
    private function resolveTargetCurrency(mixed $value): string
    {
        $target = strtoupper((string) ($value ?? 'AMD'));

        return $target !== '' ? $target : 'AMD';
    }

    /**
     * Resolve the CALLER's own company from the authenticated membership —
     * never from a body company_id. Super/platform-admin may scope to any
     * company via ?company_id=N (used by the admin company-detail panel).
     */
    private function resolveOwnCompany(Request $request): ?Company
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $explicitId = (int) $request->query('company_id');
        if ($explicitId > 0 && $this->adminAccessService->isPlatformAdmin($user)) {
            return Company::query()->find($explicitId);
        }

        $user->loadMissing('companies');
        $first = $user->companies->sortBy('id')->first();

        return $first instanceof Company ? $first : null;
    }

    /**
     * Map a company TYPE to a SellerFxRate SCOPE. Note the deliberate
     * naming mismatch: Company::TYPES uses 'agency' but the scope enum uses
     * 'agent'. Returns null for company types that don't manage FX
     * (airline / hotel_chain / other) so the caller can 422.
     */
    private function scopeForCompany(Company $company): ?string
    {
        return match ($company->type) {
            'operator' => SellerFxRate::SCOPE_OPERATOR,
            'agency' => SellerFxRate::SCOPE_AGENT,
            default => null,
        };
    }

    /**
     * Write gate for the self-service path. Super-admin bypass, else the
     * AGENT-AWARE canManageSellerStatus (admits operator owners AND agency
     * owners whose membership role is `agent`). 403 otherwise.
     */
    private function denyUnlessCanManageFx(Request $request, Company $company): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return null;
        }
        if ($this->companyAccessService->canManageSellerStatus($user, $company)) {
            return null;
        }

        return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
    }

    /** @return array<string, mixed> */
    private function serialize(SellerFxRate $row): array
    {
        return [
            'id' => $row->id,
            'scope' => $row->scope,
            'company_id' => $row->company_id !== null ? (int) $row->company_id : null,
            'company_name' => $row->relationLoaded('company') ? $row->company?->name : null,
            'target_currency' => $row->target_currency,
            'source' => $row->source,
            'margin' => (float) $row->margin,
            'manual_rates' => $row->manual_rates,
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
