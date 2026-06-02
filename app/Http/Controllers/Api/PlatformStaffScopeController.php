<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformStaffScope;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RBAC blueprint Phase 4 — super-admin management of a platform-staff user's
 * data assignments (which companies / countries a platform_admin may see).
 *
 * Super-admin only: only a full super-admin assigns staff scopes — a
 * platform_admin must not be able to widen their own visibility. The assigned
 * set is resolved into visible companies by
 * AdminAccessService::assignedCompanyIds(), which feeds visibleCompanyIds().
 */
class PlatformStaffScopeController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    /** GET /platform-admin/staff/{user}/scopes */
    public function show(Request $request, User $user): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $scopes = PlatformStaffScope::query()->where('user_id', $user->id)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'company_ids' => $scopes->pluck('company_id')->filter()->map(fn ($id) => (int) $id)->values(),
                'countries' => $scopes->pluck('country')->filter()->unique()->values(),
                'resolved_company_ids' => $this->adminAccessService->assignedCompanyIds($user),
                // Pickers for the super-admin UI.
                'available_companies' => Company::query()->orderBy('name')->get(['id', 'name', 'country']),
                'available_countries' => Company::query()
                    ->whereNotNull('country')
                    ->where('country', '!=', '')
                    ->distinct()
                    ->orderBy('country')
                    ->pluck('country')
                    ->values(),
            ],
        ]);
    }

    /** PUT /platform-admin/staff/{user}/scopes — full replace. */
    public function sync(Request $request, User $user): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'company_ids' => ['array'],
            'company_ids.*' => ['integer', 'exists:companies,id'],
            'countries' => ['array'],
            'countries.*' => ['string', 'max:120'],
        ]);

        $actorId = $request->user()->id;

        DB::transaction(function () use ($validated, $user, $actorId): void {
            PlatformStaffScope::query()->where('user_id', $user->id)->delete();

            foreach (array_values(array_unique($validated['company_ids'] ?? [])) as $companyId) {
                PlatformStaffScope::query()->create([
                    'user_id' => $user->id,
                    'company_id' => (int) $companyId,
                    'assigned_by_user_id' => $actorId,
                ]);
            }

            $seenCountries = [];
            foreach ($validated['countries'] ?? [] as $country) {
                $trimmed = trim((string) $country);
                $dedupeKey = mb_strtolower($trimmed);
                if ($trimmed === '' || isset($seenCountries[$dedupeKey])) {
                    continue;
                }
                $seenCountries[$dedupeKey] = true;
                PlatformStaffScope::query()->create([
                    'user_id' => $user->id,
                    'country' => $trimmed,
                    'assigned_by_user_id' => $actorId,
                ]);
            }
        });

        return $this->show($request, $user);
    }

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }
}
