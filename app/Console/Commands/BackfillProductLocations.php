<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackfillProductLocations extends Command
{
    protected $signature = 'locations:backfill
                            {--chunk=100 : Chunk size for batch processing}
                            {--dry-run : Resolve and report without updating database}';

    protected $description = 'Backfill product location_id/origin_location_id/destination_location_id from legacy location text fields.';

    /** @var array<int, array<string, mixed>> */
    private array $locationRows = [];

    /** @var array<int, array<string, mixed>> */
    private array $locationsById = [];

    /** @var array<string, array<string, list<int>>> */
    private array $idsByTypeName = [];

    /** @var array<string, array<string, list<int>>> */
    private array $idsByTypeSlug = [];

    /** @var array<int, string> */
    private array $legacyCountryNameById = [];

    private bool $dryRun = false;

    private string $reportPath = '';

    /** @var array<string, array<string, int>> */
    private array $stats = [];

    public function handle(): int
    {
        $chunkSize = max(10, (int) $this->option('chunk'));
        $this->dryRun = (bool) $this->option('dry-run');

        $this->bootstrapReportFile();
        $this->bootstrapLocationIndexes();
        $this->bootstrapLegacyCountryIndex();

        if ($this->locationRows === []) {
            $this->error('No rows found in locations table. Backfill cannot run.');

            return self::FAILURE;
        }

        $this->info('Starting location backfill...');
        $this->line('Report: '.$this->reportPath);
        if ($this->dryRun) {
            $this->warn('Dry run mode is enabled: database updates are skipped.');
        }

        $this->backfillHotels($chunkSize);
        $this->backfillFlights($chunkSize);
        $this->backfillCars($chunkSize);
        $this->backfillVisas($chunkSize);
        $this->backfillExcursions($chunkSize);
        $this->backfillTransfers($chunkSize);

        $this->renderSummary();

        return self::SUCCESS;
    }

    private function bootstrapReportFile(): void
    {
        $relative = 'reports/location-backfill-'.now()->format('Ymd_His').'.jsonl';
        $fullPath = storage_path('app/'.$relative);
        $dir = dirname($fullPath);
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put($fullPath, '');
        $this->reportPath = $fullPath;
    }

    private function bootstrapLocationIndexes(): void
    {
        $this->locationRows = Location::query()
            ->select(['id', 'name', 'type', 'parent_id', 'slug', 'path'])
            ->orderBy('id')
            ->get()
            ->map(fn (Location $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'parent_id' => $row->parent_id !== null ? (int) $row->parent_id : null,
                'slug' => (string) ($row->slug ?? ''),
                'path' => $row->path !== null ? (string) $row->path : null,
            ])
            ->all();

        foreach ($this->locationRows as $row) {
            $id = (int) $row['id'];
            $type = (string) $row['type'];
            $nameKey = $this->normalizedKey((string) $row['name']);
            $slugKey = $this->normalizedKey((string) $row['slug']);

            $this->locationsById[$id] = $row;

            if ($nameKey !== null) {
                $this->idsByTypeName[$type][$nameKey] ??= [];
                $this->idsByTypeName[$type][$nameKey][] = $id;
            }

            if ($slugKey !== null) {
                $this->idsByTypeSlug[$type][$slugKey] ??= [];
                $this->idsByTypeSlug[$type][$slugKey][] = $id;
            }
        }
    }

    private function bootstrapLegacyCountryIndex(): void
    {
        if (! Schema::hasTable('countries')) {
            return;
        }

        $this->legacyCountryNameById = DB::table('countries')
            ->select(['id', 'name'])
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    private function backfillHotels(int $chunkSize): void
    {
        $key = 'hotels';
        $this->initStats($key);

        DB::table('hotels')
            ->select(['id', 'country', 'region_or_state', 'city', 'location_id'])
            ->whereNull('location_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($key): void {
                DB::transaction(function () use ($rows, $key): void {
                    foreach ($rows as $row) {
                        $this->stats[$key]['processed']++;
                        $cityId = $this->resolveCityWithinHierarchy(
                            $row->city,
                            $row->region_or_state,
                            $row->country
                        );

                        if ($cityId === null) {
                            $this->markUnresolved(
                                $key,
                                (int) $row->id,
                                'location_id',
                                'No matching city found by city/region/country.',
                                [
                                    'country' => $row->country,
                                    'region_or_state' => $row->region_or_state,
                                    'city' => $row->city,
                                ]
                            );

                            continue;
                        }

                        $this->updateRow('hotels', (int) $row->id, ['location_id' => $cityId]);
                        $this->stats[$key]['updated']++;
                    }
                });
            }, 'id');
    }

    private function backfillFlights(int $chunkSize): void
    {
        $key = 'flights';
        $this->initStats($key);

        DB::table('flights')
            ->select(['id', 'departure_country', 'departure_city', 'arrival_country', 'arrival_city', 'location_id'])
            ->whereNull('location_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($key): void {
                DB::transaction(function () use ($rows, $key): void {
                    foreach ($rows as $row) {
                        $this->stats[$key]['processed']++;

                        $locationId = $this->resolveCityWithinHierarchy(
                            $row->departure_city,
                            null,
                            $row->departure_country
                        );

                        if ($locationId === null) {
                            $locationId = $this->resolveCityWithinHierarchy(
                                $row->arrival_city,
                                null,
                                $row->arrival_country
                            );
                        }

                        if ($locationId === null) {
                            $this->markUnresolved(
                                $key,
                                (int) $row->id,
                                'location_id',
                                'No matching city found by departure/arrival fields.',
                                [
                                    'departure_country' => $row->departure_country,
                                    'departure_city' => $row->departure_city,
                                    'arrival_country' => $row->arrival_country,
                                    'arrival_city' => $row->arrival_city,
                                ]
                            );

                            continue;
                        }

                        $this->updateRow('flights', (int) $row->id, ['location_id' => $locationId]);
                        $this->stats[$key]['updated']++;
                    }
                });
            }, 'id');
    }

    private function backfillCars(int $chunkSize): void
    {
        $key = 'cars';
        $this->initStats($key);

        DB::table('cars')
            ->select(['id', 'pickup_location', 'dropoff_location', 'location_id'])
            ->whereNull('location_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($key): void {
                DB::transaction(function () use ($rows, $key): void {
                    foreach ($rows as $row) {
                        $this->stats[$key]['processed']++;

                        $locationId = $this->resolveFlexibleLegacyLocation($row->pickup_location, ['city', 'region']);
                        if ($locationId === null) {
                            $locationId = $this->resolveFlexibleLegacyLocation($row->dropoff_location, ['city', 'region']);
                        }

                        if ($locationId === null) {
                            $this->markUnresolved(
                                $key,
                                (int) $row->id,
                                'location_id',
                                'No matching city/region found by pickup/dropoff text.',
                                [
                                    'pickup_location' => $row->pickup_location,
                                    'dropoff_location' => $row->dropoff_location,
                                ]
                            );

                            continue;
                        }

                        $this->updateRow('cars', (int) $row->id, ['location_id' => $locationId]);
                        $this->stats[$key]['updated']++;
                    }
                });
            }, 'id');
    }

    private function backfillVisas(int $chunkSize): void
    {
        $key = 'visas';
        $this->initStats($key);

        DB::table('visas')
            ->select(['id', 'country_id', 'country', 'location_id'])
            ->whereNull('location_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($key): void {
                DB::transaction(function () use ($rows, $key): void {
                    foreach ($rows as $row) {
                        $this->stats[$key]['processed']++;

                        $countryName = null;
                        if ($row->country_id !== null) {
                            $countryName = $this->legacyCountryNameById[(int) $row->country_id] ?? null;
                        }
                        if ($countryName === null) {
                            $countryName = is_string($row->country) ? $row->country : null;
                        }

                        $locationId = $this->resolveLocationIdByTypeAndLabel('country', $countryName);
                        if ($locationId === null) {
                            $this->markUnresolved(
                                $key,
                                (int) $row->id,
                                'location_id',
                                'No matching country found by country_id/country.',
                                [
                                    'country_id' => $row->country_id,
                                    'country' => $row->country,
                                ]
                            );

                            continue;
                        }

                        $this->updateRow('visas', (int) $row->id, ['location_id' => $locationId]);
                        $this->stats[$key]['updated']++;
                    }
                });
            }, 'id');
    }

    private function backfillExcursions(int $chunkSize): void
    {
        $key = 'excursions';
        $this->initStats($key);

        DB::table('excursions')
            ->select(['id', 'country', 'city', 'location', 'location_id'])
            ->whereNull('location_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($key): void {
                DB::transaction(function () use ($rows, $key): void {
                    foreach ($rows as $row) {
                        $this->stats[$key]['processed']++;

                        $locationId = $this->resolveCityWithinHierarchy($row->city, null, $row->country);
                        if ($locationId === null) {
                            $locationId = $this->resolveRegionWithinCountry($row->city, $row->country);
                        }
                        if ($locationId === null) {
                            $locationId = $this->resolveFlexibleLegacyLocation($row->location, ['city', 'region']);
                        }

                        if ($locationId === null) {
                            $this->markUnresolved(
                                $key,
                                (int) $row->id,
                                'location_id',
                                'No matching region/city found by excursion fields.',
                                [
                                    'country' => $row->country,
                                    'city' => $row->city,
                                    'location' => $row->location,
                                ]
                            );

                            continue;
                        }

                        $this->updateRow('excursions', (int) $row->id, ['location_id' => $locationId]);
                        $this->stats[$key]['updated']++;
                    }
                });
            }, 'id');
    }

    private function backfillTransfers(int $chunkSize): void
    {
        $key = 'transfers';
        $this->initStats($key);

        DB::table('transfers')
            ->select([
                'id',
                'pickup_country',
                'pickup_city',
                'dropoff_country',
                'dropoff_city',
                'origin_location_id',
                'destination_location_id',
            ])
            ->where(function (Builder $q): void {
                $q->whereNull('origin_location_id')->orWhereNull('destination_location_id');
            })
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($key): void {
                DB::transaction(function () use ($rows, $key): void {
                    foreach ($rows as $row) {
                        $this->stats[$key]['processed']++;
                        $updates = [];

                        if ($row->origin_location_id === null) {
                            $originId = $this->resolveCityWithinHierarchy($row->pickup_city, null, $row->pickup_country)
                                ?? $this->resolveRegionWithinCountry($row->pickup_city, $row->pickup_country);
                            if ($originId !== null) {
                                $updates['origin_location_id'] = $originId;
                            } else {
                                $this->markUnresolved(
                                    $key,
                                    (int) $row->id,
                                    'origin_location_id',
                                    'No matching origin location found.',
                                    [
                                        'pickup_country' => $row->pickup_country,
                                        'pickup_city' => $row->pickup_city,
                                    ]
                                );
                            }
                        }

                        if ($row->destination_location_id === null) {
                            $destinationId = $this->resolveCityWithinHierarchy($row->dropoff_city, null, $row->dropoff_country)
                                ?? $this->resolveRegionWithinCountry($row->dropoff_city, $row->dropoff_country);
                            if ($destinationId !== null) {
                                $updates['destination_location_id'] = $destinationId;
                            } else {
                                $this->markUnresolved(
                                    $key,
                                    (int) $row->id,
                                    'destination_location_id',
                                    'No matching destination location found.',
                                    [
                                        'dropoff_country' => $row->dropoff_country,
                                        'dropoff_city' => $row->dropoff_city,
                                    ]
                                );
                            }
                        }

                        if ($updates === []) {
                            continue;
                        }

                        $this->updateRow('transfers', (int) $row->id, $updates);
                        $this->stats[$key]['updated']++;
                    }
                });
            }, 'id');
    }

    private function updateRow(string $table, int $id, array $updates): void
    {
        if ($this->dryRun) {
            return;
        }

        $updates['updated_at'] = now();
        DB::table($table)->where('id', $id)->update($updates);
    }

    private function resolveCityWithinHierarchy(mixed $city, mixed $region, mixed $country): ?int
    {
        $cityCandidates = $this->candidateIds('city', $city);
        if ($cityCandidates === []) {
            return null;
        }

        $countryId = $this->resolveLocationIdByTypeAndLabel('country', $country);
        $regionId = $this->resolveRegionWithinCountry($region, $country);

        if ($regionId !== null) {
            $cityCandidates = array_values(array_filter(
                $cityCandidates,
                fn (int $id): bool => ((int) ($this->locationsById[$id]['parent_id'] ?? 0)) === $regionId
            ));
        }

        if ($countryId !== null) {
            $cityCandidates = array_values(array_filter(
                $cityCandidates,
                fn (int $id): bool => $this->locationHasAncestor($id, $countryId)
            ));
        }

        return $this->singleCandidateOrNull($cityCandidates);
    }

    private function resolveRegionWithinCountry(mixed $region, mixed $country): ?int
    {
        $regionCandidates = $this->candidateIds('region', $region);
        if ($regionCandidates === []) {
            return null;
        }

        $countryId = $this->resolveLocationIdByTypeAndLabel('country', $country);
        if ($countryId !== null) {
            $regionCandidates = array_values(array_filter(
                $regionCandidates,
                fn (int $id): bool => ((int) ($this->locationsById[$id]['parent_id'] ?? 0)) === $countryId
            ));
        }

        return $this->singleCandidateOrNull($regionCandidates);
    }

    private function resolveLocationIdByTypeAndLabel(string $type, mixed $rawLabel): ?int
    {
        return $this->singleCandidateOrNull($this->candidateIds($type, $rawLabel));
    }

    /**
     * @param  list<string>  $types
     */
    private function resolveFlexibleLegacyLocation(mixed $rawText, array $types): ?int
    {
        $candidates = [];
        foreach ($this->candidateTokens($rawText) as $token) {
            foreach ($types as $type) {
                $candidates = array_values(array_unique(array_merge($candidates, $this->candidateIds($type, $token))));
            }
        }

        return $this->singleCandidateOrNull($candidates);
    }

    /**
     * @return list<int>
     */
    private function candidateIds(string $type, mixed $rawLabel): array
    {
        $token = $this->normalizedKey(is_string($rawLabel) || is_numeric($rawLabel) ? (string) $rawLabel : null);
        if ($token === null) {
            return [];
        }

        $slugToken = $this->normalizedKey(Str::slug($token));

        $ids = [];
        if (isset($this->idsByTypeName[$type][$token])) {
            $ids = array_merge($ids, $this->idsByTypeName[$type][$token]);
        }
        if (isset($this->idsByTypeSlug[$type][$token])) {
            $ids = array_merge($ids, $this->idsByTypeSlug[$type][$token]);
        }
        if ($slugToken !== null && isset($this->idsByTypeSlug[$type][$slugToken])) {
            $ids = array_merge($ids, $this->idsByTypeSlug[$type][$slugToken]);
        }

        return array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $ids)));
    }

    /**
     * @return list<string>
     */
    private function candidateTokens(mixed $rawText): array
    {
        $text = is_string($rawText) || is_numeric($rawText) ? trim((string) $rawText) : '';
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/[,\/|-]+/', $text) ?: [];
        $tokens = [$text];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $tokens[] = $part;
            }
        }

        return array_values(array_unique($tokens));
    }

    private function locationHasAncestor(int $locationId, int $ancestorId): bool
    {
        if ($locationId === $ancestorId) {
            return true;
        }

        $cursorId = $locationId;
        $guard = 0;
        while ($guard < 30 && isset($this->locationsById[$cursorId])) {
            $guard++;
            $row = $this->locationsById[$cursorId];
            $parentId = $row['parent_id'] ?? null;
            if (! is_int($parentId) || $parentId <= 0) {
                return false;
            }
            if ($parentId === $ancestorId) {
                return true;
            }
            $cursorId = $parentId;
        }

        return false;
    }

    private function singleCandidateOrNull(array $ids): ?int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (mixed $id): int => (int) $id, $ids),
            fn (int $id): bool => $id > 0
        )));

        if (count($ids) !== 1) {
            return null;
        }

        return $ids[0];
    }

    private function normalizedKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    private function initStats(string $key): void
    {
        $this->stats[$key] = [
            'processed' => 0,
            'updated' => 0,
            'unresolved' => 0,
        ];
    }

    private function markUnresolved(string $entity, int $id, string $field, string $reason, array $context): void
    {
        $this->stats[$entity]['unresolved']++;
        $payload = [
            'entity' => $entity,
            'id' => $id,
            'field' => $field,
            'reason' => $reason,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
        ];
        File::append($this->reportPath, json_encode($payload, JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    private function renderSummary(): void
    {
        $headers = ['Entity', 'Processed', 'Updated', 'Unresolved'];
        $rows = [];
        $totalProcessed = 0;
        $totalUpdated = 0;
        $totalUnresolved = 0;

        foreach ($this->stats as $entity => $data) {
            $rows[] = [
                $entity,
                (string) $data['processed'],
                (string) $data['updated'],
                (string) $data['unresolved'],
            ];
            $totalProcessed += $data['processed'];
            $totalUpdated += $data['updated'];
            $totalUnresolved += $data['unresolved'];
        }

        $this->table($headers, $rows);
        $this->newLine();
        $this->info('Backfill complete.');
        $this->line('Total processed: '.$totalProcessed);
        $this->line('Total updated: '.$totalUpdated);
        $this->line('Total unresolved: '.$totalUnresolved);
        $this->line('Reconciliation report: '.$this->reportPath);
    }
}

