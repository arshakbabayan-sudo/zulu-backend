<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase Զ.15 / Item 16-17 — manual FX rate admin CRUD.
 *
 * Pre-Phase Զ.15 admins could only set manual rates via SQL/tinker. This
 * controller surfaces the same operations as a REST API so the new
 * /settings/exchange-rates UI can manage them.
 *
 * Read access: any platform admin (operator / platform / super).
 * Write access: super-admin only.
 *
 * Endpoints:
 *   GET    /api/platform-admin/exchange-rates           — list active + recent
 *   POST   /api/platform-admin/exchange-rates           — create manual override
 *   PATCH  /api/platform-admin/exchange-rates/{id}      — update (deactivate / re-rate)
 *   DELETE /api/platform-admin/exchange-rates/{id}      — soft-deactivate (is_active=false)
 *
 * The `source` enum is forced to 'manual' on create; the provider chain
 * picks manual rows over CBA/ECB per ExchangeRate::SOURCE_PRECEDENCE.
 */
class ExchangeRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $query = ExchangeRate::query();

        if ($pair = $request->query('pair')) {
            // pair shape "USD-EUR" → filter both columns
            [$src, $tgt] = array_pad(explode('-', strtoupper((string) $pair)), 2, null);
            if ($src && $tgt) {
                $query->where('source_currency', $src)->where('target_currency', $tgt);
            }
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if (($active = $request->query('is_active')) !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 100)));

        $rows = $query
            ->orderByDesc('is_active')
            ->orderByDesc('fetched_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden — super-admin only'], 403);
        }

        $data = $request->validate([
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3', 'different:source_currency'],
            'rate' => ['required', 'numeric', 'min:0.000001'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rate = ExchangeRate::create([
            'source_currency' => strtoupper($data['source_currency']),
            'target_currency' => strtoupper($data['target_currency']),
            'rate' => (string) $data['rate'],
            'source' => ExchangeRate::SOURCE_MANUAL,
            'fetched_at' => now(),
            'is_active' => $data['is_active'] ?? true,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $rate], 201);
    }

    public function update(Request $request, ExchangeRate $exchangeRate): JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden — super-admin only'], 403);
        }

        $data = $request->validate([
            'rate' => ['sometimes', 'numeric', 'min:0.000001'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $exchangeRate->fill($data);
        if (isset($data['rate'])) {
            $exchangeRate->fetched_at = now();
        }
        $exchangeRate->save();

        return response()->json(['success' => true, 'data' => $exchangeRate->fresh()]);
    }

    public function destroy(Request $request, ExchangeRate $exchangeRate): JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden — super-admin only'], 403);
        }

        // Soft-deactivate rather than hard-delete so historical resolved
        // prices that already snapshotted this rate remain auditable.
        $exchangeRate->is_active = false;
        $exchangeRate->save();

        return response()->json(['success' => true]);
    }
}
