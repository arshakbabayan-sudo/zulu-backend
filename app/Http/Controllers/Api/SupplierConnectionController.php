<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesCommerceAccess;
use App\Http\Controllers\Controller;
use App\Models\SupplierConnection;
use App\Services\Admin\AdminAccessService;
use App\Services\Suppliers\SupplierConnectorClient;
use App\Services\Suppliers\SupplierImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roadmap §4 — External API: operator ↔ external inventory base connections.
 *
 * Writes are gated on `offers.create` for the connection's company (import
 * lands draft offers, so the gate matches what the action actually does).
 * Disconnecting deletes the connection + its ledger only — imported
 * inventory always stays.
 */
class SupplierConnectionController extends Controller
{
    use AuthorizesCommerceAccess;

    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = SupplierConnection::query()->with('company:id,name')->orderBy('id');

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->whereIn('company_id', $companyIds);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn ($c) => $this->serialize($c))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'base_url' => ['required', 'url', 'max:500'],
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No active company.'], 422);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'offers.create')) {
            return $response;
        }

        $connection = SupplierConnection::query()->create([
            'company_id' => $companyId,
            'name' => ($validated['name'] ?? null) ?: (parse_url($validated['base_url'], PHP_URL_HOST) ?: 'External base'),
            'base_url' => $validated['base_url'],
            'login' => $validated['login'],
            'password' => $validated['password'],
            'status' => SupplierConnection::STATUS_UNTESTED,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($connection->fresh('company')),
        ], 201);
    }

    public function test(Request $request, int $id, SupplierConnectorClient $client): JsonResponse
    {
        $connection = $this->resolveOwned($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if ($response = $this->ensureCommerceAccess($request, (int) $connection->company_id, 'offers.create')) {
            return $response;
        }

        $result = $client->ping($connection);

        $connection->update([
            'status' => $result['ok'] ? SupplierConnection::STATUS_OK : SupplierConnection::STATUS_FAILED,
            'last_tested_at' => now(),
            'last_error' => $result['error'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'ok' => $result['ok'],
                'error' => $result['error'],
                'connection' => $this->serialize($connection->fresh('company')),
            ],
        ]);
    }

    public function import(Request $request, int $id, SupplierImportService $importService): JsonResponse
    {
        $connection = $this->resolveOwned($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if ($response = $this->ensureCommerceAccess($request, (int) $connection->company_id, 'offers.create')) {
            return $response;
        }

        $summary = $importService->import($connection);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'connection' => $this->serialize($connection->fresh('company')),
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $connection = $this->resolveOwned($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if ($response = $this->ensureCommerceAccess($request, (int) $connection->company_id, 'offers.create')) {
            return $response;
        }

        $connection->delete();

        return response()->json(['success' => true, 'data' => ['deleted_id' => $id]]);
    }

    private function resolveOwned(Request $request, int $id): ?SupplierConnection
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $connection = SupplierConnection::query()->find($id);
        if ($connection === null) {
            return null;
        }
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return $connection;
        }
        $companyIds = $user->companies()->pluck('companies.id')->all();

        return in_array((int) $connection->company_id, $companyIds, true) ? $connection : null;
    }

    /** @return array<string, mixed> */
    private function serialize(SupplierConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'company_id' => $connection->company_id,
            'company_name' => $connection->company?->name,
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'login' => $connection->login,
            'status' => $connection->status,
            'last_tested_at' => $connection->last_tested_at?->toIso8601String(),
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'items_imported' => (int) $connection->items_imported,
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }
}
