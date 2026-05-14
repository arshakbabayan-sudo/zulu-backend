<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            $countries = Location::query()
                ->countries()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $countries->map(fn (Location $country): array => $this->countryRow($country))->values(),
            ]);
        }

        $action = (string) $request->input('action', 'create');

        if ($action === 'delete') {
            $validated = $request->validate([
                'id' => ['required', 'integer'],
            ]);

            $country = $this->findCountryOrFail((int) $validated['id']);
            if ($this->hasChildren($country)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete country with child locations',
                ], 409);
            }

            $country->delete();

            return response()->json([
                'success' => true,
                'message' => 'Country deleted',
            ]);
        }

        if ($action === 'update') {
            $validated = $request->validate([
                'id' => ['required', 'integer'],
                'name' => ['required', 'string', 'max:150'],
                'code' => [
                    'required',
                    'string',
                    'size:2',
                    Rule::unique('locations', 'code')
                        ->where(fn ($query) => $query->where('type', Location::TYPE_COUNTRY))
                        ->ignore((int) $request->input('id')),
                ],
                'flag_emoji' => ['nullable', 'string', 'max:16'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ]);

            $country = $this->findCountryOrFail((int) $validated['id']);
            $country->name = $validated['name'];
            $country->code = strtoupper($validated['code']);
            $country->country_code = strtoupper($validated['code']);
            $country->flag_emoji = $validated['flag_emoji'] ?? null;
            $country->is_active = $validated['is_active'] ?? true;
            $country->sort_order = $validated['sort_order'] ?? 0;
            $country->save();
            $this->refreshDescendantCountryCodes($country);

            return response()->json([
                'success' => true,
                'message' => 'Country updated',
                'data' => $this->countryRow($country->fresh()),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'size:2',
                Rule::unique('locations', 'code')
                    ->where(fn ($query) => $query->where('type', Location::TYPE_COUNTRY)),
            ],
            'flag_emoji' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $country = DB::transaction(function () use ($validated): Location {
            $country = new Location;
            $country->name = $validated['name'];
            $country->type = Location::TYPE_COUNTRY;
            $country->slug = $this->makeSlug($validated['name']);
            $country->code = strtoupper($validated['code']);
            $country->country_code = strtoupper($validated['code']);
            $country->flag_emoji = $validated['flag_emoji'] ?? null;
            $country->is_active = $validated['is_active'] ?? true;
            $country->sort_order = $validated['sort_order'] ?? 0;
            $country->parent_id = null;
            $country->depth = 0;
            $country->path = '';
            $country->save();

            $this->recomputeNodePath($country);

            return $country->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Country created',
            'data' => $this->countryRow($country),
        ], 201);
    }

    public function treeChildren(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $parentId = $validated['parent_id'] ?? null;
        $query = Location::query()->orderBy('name');

        if ($parentId === null) {
            $query->whereNull('parent_id')->countries();
        } else {
            $query->where('parent_id', (int) $parentId);
        }

        $rows = $query->get(['id', 'name', 'type', 'parent_id', 'path', 'depth', 'country_code', 'flag_emoji']);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function treeNode(Request $request, int $id): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $location = Location::query()->findOrFail($id);
        $ancestors = $location->ancestors()->values()->map(fn (Location $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'type' => $row->type,
            'parent_id' => $row->parent_id,
            'country_code' => $row->country_code,
            'flag_emoji' => $row->flag_emoji,
        ])->all();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $location->type,
                'parent_id' => $location->parent_id,
                'path' => $location->path,
                'depth' => $location->depth,
                'country_code' => $location->country_code,
                'flag_emoji' => $location->flag_emoji,
                'full_path_name' => $location->fullPathName(),
                'ancestors' => $ancestors,
            ],
        ]);
    }

    /**
     * Public type-ahead search for the customer-facing site.
     * GET /api/locations/search?q=ye&types=country,region,city&limit=10
     */
    public function searchPublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // q is optional when `types` is provided — lets clients ask
            // "list all countries" without inventing a placeholder query.
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'types' => ['sometimes', 'nullable', 'string'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            // Restrict result set to a single country (ISO-3166 alpha-2 code).
            // Useful for the city dropdown after the user picks a country.
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        $q = trim($validated['q'] ?? '');
        $limit = (int) ($validated['limit'] ?? 12);
        $countryCode = isset($validated['country_code']) ? strtoupper(trim((string) $validated['country_code'])) : null;

        $allowedTypes = ['country', 'region', 'city'];
        $types = $allowedTypes;
        if (! empty($validated['types'])) {
            $requested = array_filter(array_map('trim', explode(',', $validated['types'])));
            $types = array_values(array_intersect($allowedTypes, $requested));
            if (empty($types)) {
                $types = $allowedTypes;
            }
        }

        // Prefix match first, then contains
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        $base = Location::query()
            ->whereIn('type', $types)
            ->where('is_active', true);

        if ($countryCode !== null && $countryCode !== '') {
            $base->where('country_code', $countryCode);
        }

        if ($q !== '') {
            $base->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like.'%')
                    ->orWhere('name', 'ilike', '%'.$like.'%');
            })->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', [$like.'%']);
        }

        $rows = $base
            ->orderByRaw("CASE type WHEN 'country' THEN 1 WHEN 'region' THEN 2 WHEN 'city' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'type', 'parent_id', 'country_code', 'flag_emoji', 'depth', 'path']);

        // Attach friendly hint label (e.g. "Yerevan, Armenia") + flag.
        // Region/city rows store country_code but not flag_emoji — that lives
        // only on the country root, so we look it up.
        $countryByCode = [];
        if ($rows->isNotEmpty()) {
            $codes = $rows->pluck('country_code')->filter()->unique()->all();
            if (! empty($codes)) {
                $countryByCode = Location::query()
                    ->where('type', 'country')
                    ->whereIn('country_code', $codes)
                    ->get(['country_code', 'name', 'flag_emoji'])
                    ->keyBy('country_code');
            }
        }

        $payload = $rows->map(function ($row) use ($countryByCode) {
            $country = $row->country_code ? ($countryByCode[$row->country_code] ?? null) : null;
            $countryName = $country?->name;
            $flag = $row->flag_emoji ?: ($country?->flag_emoji);

            return [
                'id' => $row->id,
                'name' => $row->name,
                'type' => $row->type,
                'country_code' => $row->country_code,
                'flag_emoji' => $flag,
                'parent_id' => $row->parent_id,
                'country_name' => $row->type === 'country' ? null : $countryName,
                'label' => ($row->type !== 'country' && $countryName) ? "{$row->name}, {$countryName}" : $row->name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function regions(Request $request, ?int $countryId = null): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        if ($request->isMethod('get')) {
            $resolvedCountryId = $countryId ?? $request->integer('country_id');
            abort_if($resolvedCountryId === null, 422, 'country_id is required');

            $country = $this->findCountryOrFail((int) $resolvedCountryId);
            $regions = Location::query()
                ->regions()
                ->where('parent_id', $country->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $regions->map(fn (Location $region): array => $this->regionRow($region))->values(),
            ]);
        }

        $action = (string) $request->input('action', 'create');

        if ($action === 'delete') {
            $validated = $request->validate([
                'id' => ['required', 'integer'],
            ]);

            $region = $this->findRegionOrFail((int) $validated['id']);
            if ($this->hasChildren($region)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete region with child locations',
                ], 409);
            }

            $region->delete();

            return response()->json([
                'success' => true,
                'message' => 'Region deleted',
            ]);
        }

        if ($action === 'update') {
            $validated = $request->validate([
                'id' => ['required', 'integer'],
                'country_id' => ['nullable', 'integer'],
                'name' => ['required', 'string', 'max:150'],
                'code' => ['nullable', 'string', 'max:32'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ]);

            $region = DB::transaction(function () use ($validated): Location {
                $region = $this->findRegionOrFail((int) $validated['id']);
                $parentCountryId = (int) ($validated['country_id'] ?? $region->parent_id);
                $country = $this->findCountryOrFail($parentCountryId);

                $region->name = $validated['name'];
                $region->slug = $this->makeSlug($validated['name']);
                $region->code = $validated['code'] ?? null;
                $region->is_active = $validated['is_active'] ?? true;
                $region->sort_order = $validated['sort_order'] ?? 0;
                $region->parent_id = $country->id;
                $region->country_code = $country->country_code ?? $country->code;
                $region->save();

                $this->recomputeSubtree($region);
                $this->refreshDescendantCountryCodes($region);

                return $region->fresh();
            });

            return response()->json([
                'success' => true,
                'message' => 'Region updated',
                'data' => $this->regionRow($region),
            ]);
        }

        $validated = $request->validate([
            'country_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $region = DB::transaction(function () use ($validated): Location {
            $country = $this->findCountryOrFail((int) $validated['country_id']);
            $region = new Location;
            $region->name = $validated['name'];
            $region->type = Location::TYPE_REGION;
            $region->slug = $this->makeSlug($validated['name']);
            $region->code = $validated['code'] ?? null;
            $region->is_active = $validated['is_active'] ?? true;
            $region->sort_order = $validated['sort_order'] ?? 0;
            $region->country_code = $country->country_code ?? $country->code;
            $region->parent_id = $country->id;
            $region->depth = 0;
            $region->path = '';
            $region->save();

            $this->recomputeNodePath($region);

            return $region->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Region created',
            'data' => $this->regionRow($region),
        ], 201);
    }

    public function cities(Request $request, ?int $regionId = null): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        if ($request->isMethod('get')) {
            $resolvedRegionId = $regionId ?? $request->integer('region_id');
            abort_if($resolvedRegionId === null, 422, 'region_id is required');

            $region = $this->findRegionOrFail((int) $resolvedRegionId);
            $cities = Location::query()
                ->cities()
                ->where('parent_id', $region->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $cities->map(fn (Location $city): array => $this->cityRow($city))->values(),
            ]);
        }

        $action = (string) $request->input('action', 'create');

        if ($action === 'delete') {
            $validated = $request->validate([
                'id' => ['required', 'integer'],
            ]);

            $city = $this->findCityOrFail((int) $validated['id']);
            if ($this->hasChildren($city)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete city with child locations',
                ], 409);
            }

            $city->delete();

            return response()->json([
                'success' => true,
                'message' => 'City deleted',
            ]);
        }

        if ($action === 'update') {
            $validated = $request->validate([
                'id' => ['required', 'integer'],
                'region_id' => ['nullable', 'integer'],
                'country_id' => ['nullable', 'integer'],
                'name' => ['required', 'string', 'max:150'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'latitude' => ['nullable', 'numeric'],
                'longitude' => ['nullable', 'numeric'],
            ]);

            $city = DB::transaction(function () use ($validated): Location {
                $city = $this->findCityOrFail((int) $validated['id']);
                $targetRegionId = (int) ($validated['region_id'] ?? $city->parent_id);
                $region = $this->findRegionOrFail($targetRegionId);
                $country = $this->findCountryOrFail((int) $region->parent_id);

                if (isset($validated['country_id']) && (int) $validated['country_id'] !== $country->id) {
                    abort(422, 'country_id does not match the selected region');
                }

                $city->name = $validated['name'];
                $city->slug = $this->makeSlug($validated['name']);
                $city->is_active = $validated['is_active'] ?? true;
                $city->sort_order = $validated['sort_order'] ?? 0;
                $city->latitude = $validated['latitude'] ?? null;
                $city->longitude = $validated['longitude'] ?? null;
                $city->parent_id = $region->id;
                $city->country_code = $country->country_code ?? $country->code;
                $city->save();

                $this->recomputeSubtree($city);
                $this->refreshDescendantCountryCodes($city);

                return $city->fresh();
            });

            return response()->json([
                'success' => true,
                'message' => 'City updated',
                'data' => $this->cityRow($city),
            ]);
        }

        $validated = $request->validate([
            'region_id' => ['required', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $city = DB::transaction(function () use ($validated): Location {
            $region = $this->findRegionOrFail((int) $validated['region_id']);
            $country = $this->findCountryOrFail((int) $region->parent_id);
            if (isset($validated['country_id']) && (int) $validated['country_id'] !== $country->id) {
                abort(422, 'country_id does not match the selected region');
            }

            $city = new Location;
            $city->name = $validated['name'];
            $city->type = Location::TYPE_CITY;
            $city->slug = $this->makeSlug($validated['name']);
            $city->is_active = $validated['is_active'] ?? true;
            $city->sort_order = $validated['sort_order'] ?? 0;
            $city->latitude = $validated['latitude'] ?? null;
            $city->longitude = $validated['longitude'] ?? null;
            $city->country_code = $country->country_code ?? $country->code;
            $city->parent_id = $region->id;
            $city->depth = 0;
            $city->path = '';
            $city->save();

            $this->recomputeNodePath($city);

            return $city->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'City created',
            'data' => $this->cityRow($city),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function countryRow(Location $country): array
    {
        $regionsCount = Location::query()
            ->regions()
            ->where('parent_id', $country->id)
            ->count();
        $citiesCount = Location::query()
            ->cities()
            ->whereIn(
                'parent_id',
                Location::query()
                    ->regions()
                    ->where('parent_id', $country->id)
                    ->select('id')
            )
            ->count();

        return [
            'id' => (int) $country->id,
            'name' => $country->name,
            'code' => $country->code,
            'flag_emoji' => $country->flag_emoji,
            'is_active' => (bool) ($country->is_active ?? true),
            'sort_order' => (int) ($country->sort_order ?? 0),
            'regions_count' => $regionsCount,
            'cities_count' => $citiesCount,
            'created_at' => $country->created_at,
            'updated_at' => $country->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function regionRow(Location $region): array
    {
        return [
            'id' => (int) $region->id,
            'country_id' => (int) $region->parent_id,
            'name' => $region->name,
            'code' => $region->code,
            'is_active' => (bool) ($region->is_active ?? true),
            'sort_order' => (int) ($region->sort_order ?? 0),
            'cities_count' => Location::query()
                ->cities()
                ->where('parent_id', $region->id)
                ->count(),
            'created_at' => $region->created_at,
            'updated_at' => $region->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cityRow(Location $city): array
    {
        $region = Location::query()
            ->regions()
            ->whereKey($city->parent_id)
            ->first();

        return [
            'id' => (int) $city->id,
            'region_id' => (int) $city->parent_id,
            'country_id' => $region !== null ? (int) $region->parent_id : null,
            'name' => $city->name,
            'is_active' => (bool) ($city->is_active ?? true),
            'sort_order' => (int) ($city->sort_order ?? 0),
            'latitude' => $city->latitude,
            'longitude' => $city->longitude,
            'created_at' => $city->created_at,
            'updated_at' => $city->updated_at,
        ];
    }

    private function findCountryOrFail(int $id): Location
    {
        return Location::query()->countries()->findOrFail($id);
    }

    private function findRegionOrFail(int $id): Location
    {
        return Location::query()->regions()->findOrFail($id);
    }

    private function findCityOrFail(int $id): Location
    {
        return Location::query()->cities()->findOrFail($id);
    }

    private function hasChildren(Location $node): bool
    {
        return Location::query()->where('parent_id', $node->id)->exists();
    }

    private function makeSlug(string $name): string
    {
        $base = trim((string) str($name)->slug('-'));

        return $base !== '' ? $base : 'location';
    }

    private function recomputeSubtree(Location $root): void
    {
        $this->recomputeNodePath($root);

        $children = Location::query()
            ->where('parent_id', $root->id)
            ->orderBy('id')
            ->get();

        foreach ($children as $child) {
            $this->recomputeSubtree($child);
        }
    }

    private function recomputeNodePath(Location $node): void
    {
        $node->refresh();
        $parent = $node->parent_id !== null ? Location::query()->find($node->parent_id) : null;
        $node->depth = $parent !== null ? ((int) $parent->depth) + 1 : 0;

        if ($parent !== null) {
            $parentPath = trim((string) ($parent->path ?: $parent->id), '/');
            $node->path = $parentPath.'/'.$node->id;
        } else {
            $node->path = (string) $node->id;
        }

        $node->save();
    }

    private function refreshDescendantCountryCodes(Location $node): void
    {
        $countryCode = null;
        if ($node->type === Location::TYPE_COUNTRY) {
            $countryCode = strtoupper((string) ($node->country_code ?: $node->code ?: ''));
        } elseif ($node->type === Location::TYPE_REGION) {
            $country = Location::query()->countries()->find($node->parent_id);
            $countryCode = strtoupper((string) ($country?->country_code ?: $country?->code ?: ''));
        } elseif ($node->type === Location::TYPE_CITY) {
            $region = Location::query()->regions()->find($node->parent_id);
            $country = $region !== null ? Location::query()->countries()->find($region->parent_id) : null;
            $countryCode = strtoupper((string) ($country?->country_code ?: $country?->code ?: ''));
        }

        if ($countryCode !== null && $countryCode !== '') {
            Location::query()
                ->where('id', $node->id)
                ->orWhere('path', 'like', $node->path.'/%')
                ->update(['country_code' => $countryCode]);
        }
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
