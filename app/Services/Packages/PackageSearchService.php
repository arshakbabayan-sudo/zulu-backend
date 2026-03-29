<?php

namespace App\Services\Packages;

use App\Models\Package;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PackageSearchService
{
    public function __construct(
        private PackageService $packageService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>, fallback_used: bool}
     */
    public function search(array $filters): array
    {
        $perPage = max(1, (int) ($filters['per_page'] ?? 20));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $fallbackUsed = false;

        $query = Package::query()
            ->where('status', 'active')
            ->where('is_public', true);

        if (! empty($filters['destination_country'])) {
            $query->where('destination_country', (string) $filters['destination_country']);
        }

        if (! empty($filters['destination_city'])) {
            $query->where('destination_city', (string) $filters['destination_city']);
        }

        if (array_key_exists('adults_count', $filters) && $filters['adults_count'] !== null) {
            $query->where('adults_count', (int) $filters['adults_count']);
        }

        if (array_key_exists('duration_days', $filters) && $filters['duration_days'] !== null) {
            $requestedDuration = (int) $filters['duration_days'];

            $exactCount = (clone $query)
                ->where('duration_days', $requestedDuration)
                ->count();

            if ($exactCount < 3) {
                $fallbackUsed = true;
                $query->whereBetween('duration_days', [
                    max(1, $requestedDuration - 2),
                    $requestedDuration + 2,
                ]);
            } else {
                $query->where('duration_days', $requestedDuration);
            }
        }

        $paginator = $query
            ->orderByDesc('popularity_score')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())
                ->map(fn (Package $package): array => $package->toArray())
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'fallback_used' => $fallbackUsed,
        ];
    }

    /**
     * @param  Collection<int, Package>  $packages
     * @return Collection<int, array{primary: Package, variants: Collection<int, Package>}>
     */
    public function groupByHotel(Collection $packages): Collection
    {
        return $packages
            ->groupBy(fn (Package $package): string => strtolower(trim((string) $package->destination_city)).'|'.strtolower(trim((string) $package->destination_country)))
            ->map(function (Collection $group): array {
                $sorted = $group
                    ->sortByDesc(fn (Package $package): int => (int) ($package->popularity_score ?? 0))
                    ->values();

                /** @var Package $primary */
                $primary = $sorted->first();

                return [
                    'primary' => $primary,
                    'variants' => $sorted->slice(1)->values(),
                ];
            })
            ->values();
    }

    public function applyPopularityBoost(Package $package): void
    {
        $this->packageService->updatePopularityScore($package, 1);
    }
}
