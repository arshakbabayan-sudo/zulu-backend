<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class VerifyLocationBackfill extends Command
{
    protected $signature = 'locations:verify-backfill
                            {--strict : Require strict legacy-to-location name matching for hotels}';

    protected $description = 'Verify quality of product location backfill using legacy location fields.';

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

    /** @var array<string, array<string, int>> */
    private array $stats = [];

    /** @var array<string, array<string, bool>> */
    private array $columnCache = [];

    private bool $strict = false;

    private string $reportPath = '';

    public function handle(): int
    {
        $chunkSize = 100;
        $this->strict = (bool) $this->option('strict');

        $this->bootstrapReportFile();
        $this->bootstrapLocationIndexes();
        $this->bootstrapLegacyCountryIndex();

        if ($this->locationRows === []) {
            $this->error('No rows found in locations table. Verification cannot run.');

            return self::FAILURE;
        }

        $this->info('Starting location backfill verification...');
        $this->line('Report: '.$this->reportPath);
        if ($this->strict) {
            $this->warn('Strict mode is enabled for hotel city matching.');
        }

        $this->verifyHotels($chunkSize);
        $this->verifyFlights($chunkSize);
        $this->verifyCars($chunkSize);
        $this->verifyVisas($chunkSize);
        $this->verifyExcursions($chunkSize);
        $this->verifyTransfers($chunkSize);

        $this->renderSummary();

        $totalErrors = array_sum(array_map(
            fn (array $entity): int => $entity['errors'],
            $this->stats
        ));

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function bootstrapReportFile(): void
    {
        $relative = 'reports/location-verify-'.now()->format('Ymd_His').'.jsonl';
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

    private function verifyHotels(int $chunkSize): void
    {
        $entity = 'hotels';
        $this->initStats($entity);

        $cityExists = $this->tableHasColumn('hotels', 'city');
        $regionExists = $this->tableHasColumn('hotels', 'region_or_state');
        $countryExists = $this->tableHasColumn('hotels', 'country');

        $select = ['id', 'location_id'];
        if ($cityExists) {
            $select[] = 'city';
        }
        if ($regionExists) {
            $select[] = 'region_or_state';
        }
        if ($countryExists) {
            $select[] = 'country';
        }

        DB::table('hotels')
            ->select($select)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($entity, $cityExists, $regionExists, $countryExists): void {
                foreach ($rows as $row) {
                    $hasAnomaly = false;
                    $id = (int) $row->id;
                    $locationId = $this->nullableInt($row->location_id ?? null);

                    if ($locationId === null) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Missing required location_id.',
                            [
                                'city' => $cityExists ? ($row->city ?? null) : null,
                                'region_or_state' => $regionExists ? ($row->region_or_state ?? null) : null,
                                'country' => $countryExists ? ($row->country ?? null) : null,
                            ]
                        );
                        $this->markChecked($entity, false);
                        continue;
                    }

                    $linked = $this->locationsById[$locationId] ?? null;
                    if ($linked === null || (string) $linked['type'] !== 'city') {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Linked location must exist and have type city.',
                            [
                                'location_id' => $locationId,
                                'linked_type' => $linked['type'] ?? null,
                            ]
                        );
                    }

                    if ($linked !== null && $cityExists && $this->normalizedKey((string) ($row->city ?? '')) !== null) {
                        $city = is_string($row->city) || is_numeric($row->city) ? (string) $row->city : null;
                        $matches = $this->strict
                            ? $this->matchesLocationName($locationId, $city)
                            : $this->matchesLocationNameOrAncestorType($locationId, $city, ['region', 'country']);

                        if (! $matches) {
                            $hasAnomaly = true;
                            $this->recordAnomaly(
                                $entity,
                                $id,
                                'city',
                                'warning',
                                $this->strict
                                    ? 'Legacy city does not exactly match linked location name.'
                                    : 'Legacy city does not match linked location or its region/country ancestor.',
                                [
                                    'city' => $row->city ?? null,
                                    'region_or_state' => $regionExists ? ($row->region_or_state ?? null) : null,
                                    'country' => $countryExists ? ($row->country ?? null) : null,
                                    'location_id' => $locationId,
                                    'linked_name' => $linked['name'] ?? null,
                                ]
                            );
                        }
                    }

                    $this->markChecked($entity, ! $hasAnomaly);
                }
            }, 'id');
    }

    private function verifyFlights(int $chunkSize): void
    {
        $entity = 'flights';
        $this->initStats($entity);

        $departureCityExists = $this->tableHasColumn('flights', 'departure_city');
        $arrivalCityExists = $this->tableHasColumn('flights', 'arrival_city');

        $select = ['id', 'location_id'];
        if ($departureCityExists) {
            $select[] = 'departure_city';
        }
        if ($arrivalCityExists) {
            $select[] = 'arrival_city';
        }

        DB::table('flights')
            ->select($select)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($entity, $departureCityExists, $arrivalCityExists): void {
                foreach ($rows as $row) {
                    $hasAnomaly = false;
                    $id = (int) $row->id;
                    $locationId = $this->nullableInt($row->location_id ?? null);

                    if ($locationId === null) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Missing required location_id.',
                            [
                                'departure_city' => $departureCityExists ? ($row->departure_city ?? null) : null,
                                'arrival_city' => $arrivalCityExists ? ($row->arrival_city ?? null) : null,
                            ]
                        );
                        $this->markChecked($entity, false);
                        continue;
                    }

                    $linked = $this->locationsById[$locationId] ?? null;
                    if ($linked === null || (string) $linked['type'] !== 'city') {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Linked location must exist and have type city.',
                            [
                                'location_id' => $locationId,
                                'linked_type' => $linked['type'] ?? null,
                            ]
                        );
                    }

                    if ($linked !== null && ($departureCityExists || $arrivalCityExists)) {
                        $departureCity = $departureCityExists ? (is_string($row->departure_city) || is_numeric($row->departure_city) ? (string) $row->departure_city : null) : null;
                        $arrivalCity = $arrivalCityExists ? (is_string($row->arrival_city) || is_numeric($row->arrival_city) ? (string) $row->arrival_city : null) : null;
                        $hasLegacy = $this->normalizedKey($departureCity) !== null || $this->normalizedKey($arrivalCity) !== null;

                        if ($hasLegacy) {
                            $matchesDeparture = $departureCity !== null && $this->matchesLocationNameOrAncestorType($locationId, $departureCity, ['region', 'country']);
                            $matchesArrival = $arrivalCity !== null && $this->matchesLocationNameOrAncestorType($locationId, $arrivalCity, ['region', 'country']);

                            if (! $matchesDeparture && ! $matchesArrival) {
                                $hasAnomaly = true;
                                $this->recordAnomaly(
                                    $entity,
                                    $id,
                                    'departure_city/arrival_city',
                                    'warning',
                                    'Neither departure_city nor arrival_city matches the linked location hierarchy.',
                                    [
                                        'departure_city' => $departureCityExists ? ($row->departure_city ?? null) : null,
                                        'arrival_city' => $arrivalCityExists ? ($row->arrival_city ?? null) : null,
                                        'location_id' => $locationId,
                                        'linked_name' => $linked['name'] ?? null,
                                    ]
                                );
                            }
                        }
                    }

                    $this->markChecked($entity, ! $hasAnomaly);
                }
            }, 'id');
    }

    private function verifyCars(int $chunkSize): void
    {
        $entity = 'cars';
        $this->initStats($entity);

        $pickupExists = $this->tableHasColumn('cars', 'pickup_location');
        $dropoffExists = $this->tableHasColumn('cars', 'dropoff_location');

        $select = ['id', 'location_id'];
        if ($pickupExists) {
            $select[] = 'pickup_location';
        }
        if ($dropoffExists) {
            $select[] = 'dropoff_location';
        }

        DB::table('cars')
            ->select($select)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($entity, $pickupExists, $dropoffExists): void {
                foreach ($rows as $row) {
                    $hasAnomaly = false;
                    $id = (int) $row->id;
                    $locationId = $this->nullableInt($row->location_id ?? null);

                    if ($locationId === null) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Missing required location_id.',
                            [
                                'pickup_location' => $pickupExists ? ($row->pickup_location ?? null) : null,
                                'dropoff_location' => $dropoffExists ? ($row->dropoff_location ?? null) : null,
                            ]
                        );
                        $this->markChecked($entity, false);
                        continue;
                    }

                    $linked = $this->locationsById[$locationId] ?? null;
                    $linkedType = (string) ($linked['type'] ?? '');
                    if ($linked === null || ! in_array($linkedType, ['city', 'region'], true)) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Linked location must exist and have type city or region.',
                            [
                                'location_id' => $locationId,
                                'linked_type' => $linked['type'] ?? null,
                            ]
                        );
                    }

                    if ($linked !== null && ($pickupExists || $dropoffExists)) {
                        $pickup = $pickupExists ? (is_string($row->pickup_location) || is_numeric($row->pickup_location) ? (string) $row->pickup_location : null) : null;
                        $dropoff = $dropoffExists ? (is_string($row->dropoff_location) || is_numeric($row->dropoff_location) ? (string) $row->dropoff_location : null) : null;
                        $hasLegacy = $this->normalizedKey($pickup) !== null || $this->normalizedKey($dropoff) !== null;

                        if ($hasLegacy) {
                            $matchPickup = $pickup !== null && $this->legacyTextHasLocationTokenMatch($pickup, $locationId);
                            $matchDropoff = $dropoff !== null && $this->legacyTextHasLocationTokenMatch($dropoff, $locationId);
                            if (! $matchPickup && ! $matchDropoff) {
                                $hasAnomaly = true;
                                $this->recordAnomaly(
                                    $entity,
                                    $id,
                                    'pickup_location/dropoff_location',
                                    'warning',
                                    'Legacy pickup/dropoff text does not contain a token matching linked location hierarchy.',
                                    [
                                        'pickup_location' => $pickupExists ? ($row->pickup_location ?? null) : null,
                                        'dropoff_location' => $dropoffExists ? ($row->dropoff_location ?? null) : null,
                                        'location_id' => $locationId,
                                        'linked_name' => $linked['name'] ?? null,
                                    ]
                                );
                            }
                        }
                    }

                    $this->markChecked($entity, ! $hasAnomaly);
                }
            }, 'id');
    }

    private function verifyVisas(int $chunkSize): void
    {
        $entity = 'visas';
        $this->initStats($entity);

        $countryIdExists = $this->tableHasColumn('visas', 'country_id');
        $countryExists = $this->tableHasColumn('visas', 'country');

        $select = ['id', 'location_id'];
        if ($countryIdExists) {
            $select[] = 'country_id';
        }
        if ($countryExists) {
            $select[] = 'country';
        }

        DB::table('visas')
            ->select($select)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($entity, $countryIdExists, $countryExists): void {
                foreach ($rows as $row) {
                    $hasAnomaly = false;
                    $id = (int) $row->id;
                    $locationId = $this->nullableInt($row->location_id ?? null);

                    if ($locationId === null) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Missing required location_id.',
                            [
                                'country_id' => $countryIdExists ? ($row->country_id ?? null) : null,
                                'country' => $countryExists ? ($row->country ?? null) : null,
                            ]
                        );
                        $this->markChecked($entity, false);
                        continue;
                    }

                    $linked = $this->locationsById[$locationId] ?? null;
                    if ($linked === null || (string) $linked['type'] !== 'country') {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Linked location must exist and have type country.',
                            [
                                'location_id' => $locationId,
                                'linked_type' => $linked['type'] ?? null,
                            ]
                        );
                    }

                    if ($linked !== null && ($countryIdExists || $countryExists)) {
                        $legacyCountryName = null;
                        if ($countryIdExists && ($row->country_id ?? null) !== null) {
                            $legacyCountryName = $this->legacyCountryNameById[(int) $row->country_id] ?? null;
                        }
                        if ($legacyCountryName === null && $countryExists) {
                            $legacyCountryName = is_string($row->country) || is_numeric($row->country) ? (string) $row->country : null;
                        }

                        if ($this->normalizedKey($legacyCountryName) !== null) {
                            $expectedCountryId = $this->resolveLocationIdByTypeAndLabel('country', $legacyCountryName);
                            if ($expectedCountryId === null || $expectedCountryId !== $locationId) {
                                $hasAnomaly = true;
                                $this->recordAnomaly(
                                    $entity,
                                    $id,
                                    'country_id/country',
                                    'warning',
                                    'Legacy country reference does not resolve to the linked country location.',
                                    [
                                        'country_id' => $countryIdExists ? ($row->country_id ?? null) : null,
                                        'country' => $countryExists ? ($row->country ?? null) : null,
                                        'location_id' => $locationId,
                                        'linked_name' => $linked['name'] ?? null,
                                    ]
                                );
                            }
                        }
                    }

                    $this->markChecked($entity, ! $hasAnomaly);
                }
            }, 'id');
    }

    private function verifyExcursions(int $chunkSize): void
    {
        $entity = 'excursions';
        $this->initStats($entity);

        $cityExists = $this->tableHasColumn('excursions', 'city');
        $locationExists = $this->tableHasColumn('excursions', 'location');

        $select = ['id', 'location_id'];
        if ($cityExists) {
            $select[] = 'city';
        }
        if ($locationExists) {
            $select[] = 'location';
        }

        DB::table('excursions')
            ->select($select)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($entity, $cityExists, $locationExists): void {
                foreach ($rows as $row) {
                    $hasAnomaly = false;
                    $id = (int) $row->id;
                    $locationId = $this->nullableInt($row->location_id ?? null);

                    if ($locationId === null) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Missing required location_id.',
                            [
                                'city' => $cityExists ? ($row->city ?? null) : null,
                                'location' => $locationExists ? ($row->location ?? null) : null,
                            ]
                        );
                        $this->markChecked($entity, false);
                        continue;
                    }

                    $linked = $this->locationsById[$locationId] ?? null;
                    $linkedType = (string) ($linked['type'] ?? '');
                    if ($linked === null || ! in_array($linkedType, ['city', 'region'], true)) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'location_id',
                            'error',
                            'Linked location must exist and have type city or region.',
                            [
                                'location_id' => $locationId,
                                'linked_type' => $linked['type'] ?? null,
                            ]
                        );
                    }

                    if ($linked !== null && ($cityExists || $locationExists)) {
                        $legacyCity = $cityExists ? (is_string($row->city) || is_numeric($row->city) ? (string) $row->city : null) : null;
                        $legacyLocation = $locationExists ? (is_string($row->location) || is_numeric($row->location) ? (string) $row->location : null) : null;
                        $hasLegacy = $this->normalizedKey($legacyCity) !== null || $this->normalizedKey($legacyLocation) !== null;

                        if ($hasLegacy) {
                            $matchCity = $legacyCity !== null && $this->matchesLocationNameOrAncestorType($locationId, $legacyCity, ['country']);
                            $matchLocation = $legacyLocation !== null && $this->legacyTextHasLocationTokenMatch($legacyLocation, $locationId);

                            if (! $matchCity && ! $matchLocation) {
                                $hasAnomaly = true;
                                $this->recordAnomaly(
                                    $entity,
                                    $id,
                                    'city/location',
                                    'warning',
                                    'Legacy city/location does not match linked location hierarchy.',
                                    [
                                        'city' => $cityExists ? ($row->city ?? null) : null,
                                        'location' => $locationExists ? ($row->location ?? null) : null,
                                        'location_id' => $locationId,
                                        'linked_name' => $linked['name'] ?? null,
                                    ]
                                );
                            }
                        }
                    }

                    $this->markChecked($entity, ! $hasAnomaly);
                }
            }, 'id');
    }

    private function verifyTransfers(int $chunkSize): void
    {
        $entity = 'transfers';
        $this->initStats($entity);

        $pickupCityExists = $this->tableHasColumn('transfers', 'pickup_city');
        $pickupCountryExists = $this->tableHasColumn('transfers', 'pickup_country');
        $dropoffCityExists = $this->tableHasColumn('transfers', 'dropoff_city');
        $dropoffCountryExists = $this->tableHasColumn('transfers', 'dropoff_country');

        $select = ['id', 'origin_location_id', 'destination_location_id'];
        if ($pickupCityExists) {
            $select[] = 'pickup_city';
        }
        if ($pickupCountryExists) {
            $select[] = 'pickup_country';
        }
        if ($dropoffCityExists) {
            $select[] = 'dropoff_city';
        }
        if ($dropoffCountryExists) {
            $select[] = 'dropoff_country';
        }

        DB::table('transfers')
            ->select($select)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($entity, $pickupCityExists, $pickupCountryExists, $dropoffCityExists, $dropoffCountryExists): void {
                foreach ($rows as $row) {
                    $hasAnomaly = false;
                    $id = (int) $row->id;
                    $originId = $this->nullableInt($row->origin_location_id ?? null);
                    $destinationId = $this->nullableInt($row->destination_location_id ?? null);

                    if ($originId === null) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'origin_location_id',
                            'error',
                            'Missing required origin_location_id.',
                            [
                                'pickup_city' => $pickupCityExists ? ($row->pickup_city ?? null) : null,
                                'pickup_country' => $pickupCountryExists ? ($row->pickup_country ?? null) : null,
                            ]
                        );
                    }

                    if ($destinationId === null) {
                        $hasAnomaly = true;
                        $this->recordAnomaly(
                            $entity,
                            $id,
                            'destination_location_id',
                            'error',
                            'Missing required destination_location_id.',
                            [
                                'dropoff_city' => $dropoffCityExists ? ($row->dropoff_city ?? null) : null,
                                'dropoff_country' => $dropoffCountryExists ? ($row->dropoff_country ?? null) : null,
                            ]
                        );
                    }

                    if ($originId !== null) {
                        $origin = $this->locationsById[$originId] ?? null;
                        $originType = (string) ($origin['type'] ?? '');
                        if ($origin === null || ! in_array($originType, ['city', 'region'], true)) {
                            $hasAnomaly = true;
                            $this->recordAnomaly(
                                $entity,
                                $id,
                                'origin_location_id',
                                'error',
                                'Linked origin location must exist and have type city or region.',
                                [
                                    'origin_location_id' => $originId,
                                    'linked_type' => $origin['type'] ?? null,
                                ]
                            );
                        } elseif ($pickupCityExists || $pickupCountryExists) {
                            $pickupCity = $pickupCityExists ? (is_string($row->pickup_city) || is_numeric($row->pickup_city) ? (string) $row->pickup_city : null) : null;
                            $pickupCountry = $pickupCountryExists ? (is_string($row->pickup_country) || is_numeric($row->pickup_country) ? (string) $row->pickup_country : null) : null;
                            $hasLegacy = $this->normalizedKey($pickupCity) !== null || $this->normalizedKey($pickupCountry) !== null;
                            if ($hasLegacy) {
                                $cityMatch = $pickupCity !== null && $this->matchesLocationNameOrAncestorType($originId, $pickupCity, ['country']);
                                $countryMatch = $pickupCountry !== null && $this->locationHasAncestorTypeName($originId, 'country', $pickupCountry);
                                if (! $cityMatch || ! $countryMatch) {
                                    $hasAnomaly = true;
                                    $this->recordAnomaly(
                                        $entity,
                                        $id,
                                        'pickup_city/pickup_country',
                                        'warning',
                                        'Origin location does not align with pickup_city and pickup_country.',
                                        [
                                            'pickup_city' => $pickupCityExists ? ($row->pickup_city ?? null) : null,
                                            'pickup_country' => $pickupCountryExists ? ($row->pickup_country ?? null) : null,
                                            'origin_location_id' => $originId,
                                            'origin_linked_name' => $origin['name'] ?? null,
                                        ]
                                    );
                                }
                            }
                        }
                    }

                    if ($destinationId !== null) {
                        $destination = $this->locationsById[$destinationId] ?? null;
                        $destinationType = (string) ($destination['type'] ?? '');
                        if ($destination === null || ! in_array($destinationType, ['city', 'region'], true)) {
                            $hasAnomaly = true;
                            $this->recordAnomaly(
                                $entity,
                                $id,
                                'destination_location_id',
                                'error',
                                'Linked destination location must exist and have type city or region.',
                                [
                                    'destination_location_id' => $destinationId,
                                    'linked_type' => $destination['type'] ?? null,
                                ]
                            );
                        } elseif ($dropoffCityExists || $dropoffCountryExists) {
                            $dropoffCity = $dropoffCityExists ? (is_string($row->dropoff_city) || is_numeric($row->dropoff_city) ? (string) $row->dropoff_city : null) : null;
                            $dropoffCountry = $dropoffCountryExists ? (is_string($row->dropoff_country) || is_numeric($row->dropoff_country) ? (string) $row->dropoff_country : null) : null;
                            $hasLegacy = $this->normalizedKey($dropoffCity) !== null || $this->normalizedKey($dropoffCountry) !== null;
                            if ($hasLegacy) {
                                $cityMatch = $dropoffCity !== null && $this->matchesLocationNameOrAncestorType($destinationId, $dropoffCity, ['country']);
                                $countryMatch = $dropoffCountry !== null && $this->locationHasAncestorTypeName($destinationId, 'country', $dropoffCountry);
                                if (! $cityMatch || ! $countryMatch) {
                                    $hasAnomaly = true;
                                    $this->recordAnomaly(
                                        $entity,
                                        $id,
                                        'dropoff_city/dropoff_country',
                                        'warning',
                                        'Destination location does not align with dropoff_city and dropoff_country.',
                                        [
                                            'dropoff_city' => $dropoffCityExists ? ($row->dropoff_city ?? null) : null,
                                            'dropoff_country' => $dropoffCountryExists ? ($row->dropoff_country ?? null) : null,
                                            'destination_location_id' => $destinationId,
                                            'destination_linked_name' => $destination['name'] ?? null,
                                        ]
                                    );
                                }
                            }
                        }
                    }

                    $this->markChecked($entity, ! $hasAnomaly);
                }
            }, 'id');
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if (isset($this->columnCache[$table][$column])) {
            return $this->columnCache[$table][$column];
        }

        $exists = Schema::hasColumn($table, $column);
        $this->columnCache[$table][$column] = $exists;

        return $exists;
    }

    private function recordAnomaly(
        string $entity,
        int $id,
        string $field,
        string $severity,
        string $reason,
        array $context
    ): void {
        if ($severity === 'error') {
            $this->stats[$entity]['errors']++;
        } else {
            $this->stats[$entity]['warnings']++;
        }

        $payload = [
            'entity' => $entity,
            'id' => $id,
            'field' => $field,
            'severity' => $severity,
            'reason' => $reason,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
        ];

        File::append($this->reportPath, json_encode($payload, JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    private function initStats(string $entity): void
    {
        $this->stats[$entity] = [
            'checked' => 0,
            'errors' => 0,
            'warnings' => 0,
            'ok' => 0,
        ];
    }

    private function markChecked(string $entity, bool $ok): void
    {
        $this->stats[$entity]['checked']++;
        if ($ok) {
            $this->stats[$entity]['ok']++;
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function matchesLocationName(int $locationId, ?string $legacyValue): bool
    {
        $legacyKey = $this->normalizedKey($legacyValue);
        if ($legacyKey === null) {
            return false;
        }

        $linked = $this->locationsById[$locationId] ?? null;
        if ($linked === null) {
            return false;
        }

        return $legacyKey === $this->normalizedKey((string) ($linked['name'] ?? ''));
    }

    /**
     * @param  list<string>  $ancestorTypes
     */
    private function matchesLocationNameOrAncestorType(int $locationId, ?string $legacyValue, array $ancestorTypes): bool
    {
        if ($this->matchesLocationName($locationId, $legacyValue)) {
            return true;
        }

        $legacyKey = $this->normalizedKey($legacyValue);
        if ($legacyKey === null) {
            return false;
        }

        $cursor = $this->locationsById[$locationId] ?? null;
        $guard = 0;
        while ($guard < 30 && $cursor !== null) {
            $guard++;
            $parentId = $cursor['parent_id'] ?? null;
            if (! is_int($parentId) || $parentId <= 0) {
                break;
            }
            $parent = $this->locationsById[$parentId] ?? null;
            if ($parent === null) {
                break;
            }
            $parentType = (string) ($parent['type'] ?? '');
            if (in_array($parentType, $ancestorTypes, true) && $legacyKey === $this->normalizedKey((string) ($parent['name'] ?? ''))) {
                return true;
            }
            $cursor = $parent;
        }

        return false;
    }

    private function locationHasAncestorTypeName(int $locationId, string $ancestorType, ?string $legacyValue): bool
    {
        $legacyKey = $this->normalizedKey($legacyValue);
        if ($legacyKey === null) {
            return false;
        }

        $cursor = $this->locationsById[$locationId] ?? null;
        $guard = 0;
        while ($guard < 30 && $cursor !== null) {
            $guard++;
            $currentType = (string) ($cursor['type'] ?? '');
            if ($currentType === $ancestorType && $legacyKey === $this->normalizedKey((string) ($cursor['name'] ?? ''))) {
                return true;
            }

            $parentId = $cursor['parent_id'] ?? null;
            if (! is_int($parentId) || $parentId <= 0) {
                break;
            }

            $cursor = $this->locationsById[$parentId] ?? null;
        }

        return false;
    }

    private function legacyTextHasLocationTokenMatch(?string $legacyText, int $locationId): bool
    {
        $tokens = $this->candidateTokens($legacyText);
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if ($this->matchesLocationNameOrAncestorType($locationId, $token, ['region', 'country'])) {
                return true;
            }
        }

        return false;
    }

    private function resolveLocationIdByTypeAndLabel(string $type, mixed $rawLabel): ?int
    {
        return $this->singleCandidateOrNull($this->candidateIds($type, $rawLabel));
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

        $ids = [];
        if (isset($this->idsByTypeName[$type][$token])) {
            $ids = array_merge($ids, $this->idsByTypeName[$type][$token]);
        }
        if (isset($this->idsByTypeSlug[$type][$token])) {
            $ids = array_merge($ids, $this->idsByTypeSlug[$type][$token]);
        }

        return array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $ids)));
    }

    /**
     * @return list<string>
     */
    private function candidateTokens(?string $rawText): array
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

    private function renderSummary(): void
    {
        $headers = ['Entity', 'Checked', 'Errors', 'Warnings', 'OK'];
        $rows = [];
        $totalErrors = 0;
        $totalWarnings = 0;

        foreach ($this->stats as $entity => $data) {
            $rows[] = [
                $entity,
                (string) $data['checked'],
                (string) $data['errors'],
                (string) $data['warnings'],
                (string) $data['ok'],
            ];
            $totalErrors += $data['errors'];
            $totalWarnings += $data['warnings'];
        }

        $this->table($headers, $rows);
        $this->newLine();
        $this->line('Total errors (blocks cutover): '.$totalErrors);
        $this->line('Total warnings (review recommended): '.$totalWarnings);
        $this->line('Full JSONL report: '.$this->reportPath);
    }
}

