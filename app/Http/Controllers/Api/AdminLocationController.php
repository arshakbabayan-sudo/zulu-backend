<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\Region;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLocationController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function countries(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        if ($request->isMethod('get')) {
            $countries = Country::query()
                ->withCount(['regions', 'cities'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $countries,
            ]);
        }

        $action = (string) $request->input('action', 'create');

        if ($action === 'delete') {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'exists:countries,id'],
            ]);

            $country = Country::findOrFail($validated['id']);
            $country->delete();

            return response()->json([
                'success' => true,
                'message' => 'Country deleted',
            ]);
        }

        if ($action === 'update') {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'exists:countries,id'],
                'name' => ['required', 'string', 'max:150'],
                'code' => ['required', 'string', 'size:2'],
                'flag_emoji' => ['nullable', 'string', 'max:16'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ]);

            $country = Country::findOrFail($validated['id']);
            $country->update([
                'name' => $validated['name'],
                'code' => strtoupper($validated['code']),
                'flag_emoji' => $validated['flag_emoji'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Country updated',
                'data' => $country->fresh(),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'size:2', 'unique:countries,code'],
            'flag_emoji' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $country = Country::create([
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'flag_emoji' => $validated['flag_emoji'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Country created',
            'data' => $country,
        ], 201);
    }

    public function regions(Request $request, ?int $countryId = null): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        if ($request->isMethod('get')) {
            $regions = Region::query()
                ->where('country_id', $countryId)
                ->withCount('cities')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $regions,
            ]);
        }

        $action = (string) $request->input('action', 'create');

        if ($action === 'delete') {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'exists:regions,id'],
            ]);

            $region = Region::findOrFail($validated['id']);
            $region->delete();

            return response()->json([
                'success' => true,
                'message' => 'Region deleted',
            ]);
        }

        if ($action === 'update') {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'exists:regions,id'],
                'name' => ['required', 'string', 'max:150'],
                'code' => ['nullable', 'string', 'max:32'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ]);

            $region = Region::findOrFail($validated['id']);
            $region->update([
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Region updated',
                'data' => $region->fresh(),
            ]);
        }

        $validated = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $region = Region::create([
            'country_id' => $validated['country_id'],
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Region created',
            'data' => $region,
        ], 201);
    }

    public function cities(Request $request, ?int $regionId = null): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        if ($request->isMethod('get')) {
            $cities = City::query()
                ->where('region_id', $regionId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $cities,
            ]);
        }

        $action = (string) $request->input('action', 'create');

        if ($action === 'delete') {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'exists:cities,id'],
            ]);

            $city = City::findOrFail($validated['id']);
            $city->delete();

            return response()->json([
                'success' => true,
                'message' => 'City deleted',
            ]);
        }

        if ($action === 'update') {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'exists:cities,id'],
                'name' => ['required', 'string', 'max:150'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'latitude' => ['nullable', 'numeric'],
                'longitude' => ['nullable', 'numeric'],
            ]);

            $city = City::findOrFail($validated['id']);
            $city->update([
                'name' => $validated['name'],
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'City updated',
                'data' => $city->fresh(),
            ]);
        }

        $validated = $request->validate([
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $region = Region::findOrFail($validated['region_id']);

        $city = City::create([
            'region_id' => $region->id,
            'country_id' => $validated['country_id'] ?? $region->country_id,
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'City created',
            'data' => $city,
        ], 201);
    }

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
    }
}
