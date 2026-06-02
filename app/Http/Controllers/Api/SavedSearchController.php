<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAccessService;
use App\Services\Search\SavedSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function __construct(
        private SavedSearchService $service,
        private AdminAccessService $adminAccessService,
    ) {}

    /** GET /api/customer/saved-searches */
    public function index(Request $request): JsonResponse
    {
        $items = $this->service->listForUser($request->user());

        return response()->json(['success' => true, 'data' => $items]);
    }

    /** POST /api/customer/saved-searches */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'module_type' => ['nullable', 'string'],
            'query_string' => ['nullable', 'string', 'max:500'],
            'filters' => ['nullable', 'array'],
            'alerts_enabled' => ['nullable', 'boolean'],
            'result_count_at_save' => ['nullable', 'integer', 'min:0'],
        ]);

        $saved = $this->service->save($request->user(), $validated);

        return response()->json(['success' => true, 'data' => $saved], 201);
    }

    /** PATCH /api/customer/saved-searches/{id}/alerts */
    public function toggleAlerts(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['alerts_enabled' => ['required', 'boolean']]);

        $saved = $this->service->toggleAlerts($request->user(), $id, (bool) $validated['alerts_enabled']);
        if ($saved === null) {
            return response()->json(['success' => false, 'message' => 'Saved search not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $saved]);
    }

    /** DELETE /api/customer/saved-searches/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->service->delete($request->user(), $id)) {
            return response()->json(['success' => false, 'message' => 'Saved search not found'], 404);
        }

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    /** GET /api/search/autocomplete?q=... — public */
    public function autocomplete(Request $request): JsonResponse
    {
        $prefix = (string) $request->query('q', '');
        $suggestions = $this->service->autocomplete($prefix, 10);

        return response()->json(['success' => true, 'data' => $suggestions]);
    }

    /** GET /api/search/popular — public */
    public function popular(Request $request): JsonResponse
    {
        $popular = $this->service->popularQueries(20, 30);

        return response()->json(['success' => true, 'data' => $popular]);
    }

    /** GET /api/search/trends?days=30 — platform-staff only */
    public function trends(Request $request): JsonResponse
    {
        // Platform-wide search analytics. The admin-panel middleware now
        // admits operators, so gate here — these aggregate trends span all
        // tenants and are a platform-staff tool.
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $days = max(1, min(365, (int) $request->query('days', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->service->searchTrends($days),
        ]);
    }

    /** GET /api/search/cross-suggest?q=...&days=30 */
    public function crossSuggest(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');
        $days = max(1, min(365, (int) $request->query('days', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->service->crossVerticalMatches($q, $days, 5),
        ]);
    }
}
