<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin management of platform-wide commission % bounds per service type
 * (roadmap P0-2 / item 1.2.3). The profile-completion wizard's commission step
 * validates an operator's chosen % against these bounds via CommissionLimit::boundsFor().
 *
 * Gated by the `platform-admin` middleware on the route group.
 */
class CommissionLimitController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = CommissionLimit::query()->orderBy('service_type')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'service_types' => CommissionLimit::SERVICE_TYPES,
                'global' => CommissionLimit::boundsFor(null),
                'limits' => $rows->map(fn (CommissionLimit $row): array => [
                    'service_type' => $row->service_type,
                    'min_percent' => $row->min_percent,
                    'max_percent' => $row->max_percent,
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $allowedKeys = array_merge([CommissionLimit::GLOBAL_KEY], CommissionLimit::SERVICE_TYPES);

        $validated = $request->validate([
            'service_type' => ['required', 'string', Rule::in($allowedKeys)],
            'min_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_percent' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_percent'],
        ]);

        $row = CommissionLimit::query()->updateOrCreate(
            ['service_type' => $validated['service_type']],
            [
                'min_percent' => $validated['min_percent'],
                'max_percent' => $validated['max_percent'],
                'updated_by' => $request->user()?->id,
            ],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'service_type' => $row->service_type,
                'min_percent' => $row->min_percent,
                'max_percent' => $row->max_percent,
            ],
        ]);
    }
}
