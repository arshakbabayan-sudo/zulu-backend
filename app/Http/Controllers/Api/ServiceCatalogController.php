<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCatalogItem;
use App\Services\Admin\AdminAccessService;
use App\Services\Pricing\DisplayCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.12 — service catalog CRUD.
 */
class ServiceCatalogController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = ServiceCatalogItem::query()
            ->with(['createdBy:id,name', 'company:id,name'])
            ->orderByDesc('id');

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->whereIn('company_id', $companyIds);
        }

        if ($request->filled('search')) {
            $term = (string) $request->query('search');
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%");
            });
        }
        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn ($r) => $this->serialize($r))->values()->all(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'unit' => ['nullable', Rule::in(ServiceCatalogItem::UNITS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No active company.'], 422);
        }

        $row = ServiceCatalogItem::query()->create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'category' => $validated['category'] ?? null,
            'description' => $validated['description'] ?? null,
            'base_price' => $validated['base_price'] ?? null,
            'currency' => isset($validated['currency']) ? strtoupper($validated['currency']) : null,
            'unit' => $validated['unit'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['createdBy', 'company'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveOwned($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'unit' => ['nullable', Rule::in(ServiceCatalogItem::UNITS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        $row->fill($validated);
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['createdBy', 'company'])),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveOwned($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $row->delete();

        return response()->json(['success' => true, 'data' => ['deleted_id' => $id]]);
    }

    /**
     * Phase Զ.16 / Item 13 — public storefront search for service-catalog
     * items (meet-and-greet, luggage storage, late check-in, etc.).
     *
     * GET /api/catalog/services?search=&category=&company_id=&limit=
     *
     * Returns only `is_active=true` rows. Public endpoint — no auth.
     */
    public function publicSearch(Request $request): JsonResponse
    {
        $query = ServiceCatalogItem::query()
            ->with('company:id,name')
            ->where('is_active', true)
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = (string) $request->query('search');
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }
        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->query('company_id'));
        }

        $limit = max(1, min(100, (int) $request->query('limit', 50)));

        // Part A — DISPLAY-currency conversion (additive; charge currency unchanged).
        $display = app(DisplayCurrencyService::class);
        $displayCurrency = $display->sanitize($request->query('display_currency'));

        return response()->json([
            'success' => true,
            'data' => $query->limit($limit)->get()->map(fn ($r) => $display->attach([
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
                'category' => $r->category,
                'unit_price' => $r->unit_price,
                'currency' => $r->currency,
                'unit' => $r->unit,
                'company' => $r->company ? [
                    'id' => $r->company->id,
                    'name' => $r->company->name,
                ] : null,
            ], $r->unit_price, $r->currency, $displayCurrency))->all(),
        ]);
    }

    private function resolveOwned(Request $request, int $id): ?ServiceCatalogItem
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $row = ServiceCatalogItem::query()->find($id);
        if ($row === null) {
            return null;
        }
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return $row;
        }
        $companyIds = $user->companies()->pluck('companies.id')->all();
        return in_array((int) $row->company_id, $companyIds, true) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function serialize(ServiceCatalogItem $row): array
    {
        return [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'name' => $row->name,
            'category' => $row->category,
            'description' => $row->description,
            'base_price' => $row->base_price !== null ? (float) $row->base_price : null,
            'currency' => $row->currency,
            'unit' => $row->unit,
            'is_active' => (bool) $row->is_active,
            'created_by' => $row->createdBy ? ['id' => $row->createdBy->id, 'name' => $row->createdBy->name] : null,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
