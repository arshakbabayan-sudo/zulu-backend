<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Location extends Model
{
    use HasFactory;

    public const TYPE_COUNTRY = 'country';
    public const TYPE_REGION = 'region';
    public const TYPE_CITY = 'city';

    protected $fillable = [
        'name',
        'type',
        'parent_id',
        'slug',
        'depth',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'depth' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class, 'location_id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class, 'location_id');
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class, 'location_id');
    }

    public function visas(): HasMany
    {
        return $this->hasMany(Visa::class, 'location_id');
    }

    public function excursions(): HasMany
    {
        return $this->hasMany(Excursion::class, 'location_id');
    }

    public function excursionAssignments(): BelongsToMany
    {
        return $this->belongsToMany(Excursion::class, 'excursion_location')
            ->withPivot(['is_primary', 'sort_order'])
            ->withTimestamps();
    }

    public function originTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'origin_location_id');
    }

    public function destinationTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'destination_location_id');
    }

    public function ancestors(): Collection
    {
        $ids = $this->ancestorIdsFromPath();
        if ($ids !== []) {
            return $this->orderedLocationsByIds($ids);
        }

        $lineage = [];
        $cursor = $this->parent;

        while ($cursor !== null) {
            array_unshift($lineage, $cursor);
            $cursor = $cursor->parent;
        }

        return new Collection($lineage);
    }

    public function fullPathName(): string
    {
        $cacheKey = $this->fullPathCacheKey();

        return Cache::remember($cacheKey, now()->addMinutes(10), function (): string {
            $names = $this->ancestors()
                ->pluck('name')
                ->push($this->name)
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->values();

            return $names->implode(', ');
        });
    }

    public function getFullPathNameAttribute(): string
    {
        return $this->fullPathName();
    }

    public function scopeCountries(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_COUNTRY);
    }

    public function scopeRegions(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_REGION);
    }

    public function scopeCities(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CITY);
    }

    public function isCountry(): bool
    {
        return $this->type === self::TYPE_COUNTRY;
    }

    public function isRegion(): bool
    {
        return $this->type === self::TYPE_REGION;
    }

    public function isCity(): bool
    {
        return $this->type === self::TYPE_CITY;
    }

    /**
     * @return list<int>
     */
    public static function subtreeLocationIds(int $locationId): array
    {
        if ($locationId <= 0) {
            return [];
        }

        $node = self::query()
            ->select(['id', 'path'])
            ->find($locationId);

        if ($node === null) {
            return [];
        }

        $nodePath = self::normalizedNodePath($node);
        if ($nodePath === null) {
            return [(int) $node->id];
        }

        return self::query()
            ->where('path', $nodePath)
            ->orWhere('path', 'like', $nodePath.'/%')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function ancestorIdsFromPath(): array
    {
        $ids = $this->pathIds();
        if ($ids === []) {
            return [];
        }

        if (end($ids) === (int) $this->id) {
            array_pop($ids);
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function pathIds(): array
    {
        if ($this->path === null || trim($this->path) === '') {
            return [];
        }

        $parts = preg_split('/[\/,]+/', $this->path) ?: [];

        return collect($parts)
            ->map(fn (string $part): int => (int) trim($part))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function orderedLocationsByIds(array $ids): Collection
    {
        $locations = self::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = collect($ids)
            ->map(fn (int $id): ?self => $locations->get($id))
            ->filter()
            ->values()
            ->all();

        return new Collection($ordered);
    }

    private function fullPathCacheKey(): string
    {
        $stamp = $this->updated_at?->timestamp ?? 0;

        return "locations:full_path:{$this->id}:{$stamp}";
    }

    private static function normalizedNodePath(self $location): ?string
    {
        $path = trim((string) ($location->path ?? ''));

        if ($path !== '') {
            return $path;
        }

        return isset($location->id) && (int) $location->id > 0 ? (string) $location->id : null;
    }
}

