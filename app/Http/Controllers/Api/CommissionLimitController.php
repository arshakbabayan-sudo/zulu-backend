<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionLimit;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin management of platform-wide commission % bounds per service type
 * (roadmap P0-2 / item 1.2.3). The profile-completion wizard's commission step
 * validates an operator's chosen % against these bounds via CommissionLimit::boundsFor().
 *
 * Platform-staff-only: the admin-panel middleware now admits operators (so they
 * can reach their own scoped data), so this controller carries its own
 * isPlatformAdmin gate — these bounds are platform-wide settings an operator
 * must never read or write.
 */
class CommissionLimitController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

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
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

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
