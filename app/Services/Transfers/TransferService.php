<?php

namespace App\Services\Transfers;

use App\Models\Offer;
use App\Models\Location;
use App\Models\Transfer;
use App\Services\Infrastructure\PlatformSettingsService;
use App\Services\Locations\LocationBusinessValidator;
use App\Services\Offers\OfferVisibilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferService
{
    /**
     * Whitelisted query keys for operator transfer listing ({@see applyListingFilters}).
     * Query param {@code vehicle_type} is accepted as a legacy alias for {@code vehicle_category}
     * in {@see listingFiltersFromRequest} only.
     *
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'company_id',
        'location_id',
        'status',
        'availability_status',
        'transfer_type',
        'vehicle_category',
        'is_package_eligible',
        // Step C3 — appearance/visibility context filter for inventory listings.
        'appearance_context',
        // Step C2 — advanced filters (inventory + operator listing).
        'country',
        'city',
        'origin',
        'destination',
        'trip_date',
        'trip_date_from',
        'trip_date_to',
        'passenger',
        'passengers',
        'user_email',
        'order_number',
        'invoice_id',
        'price',
        'price_min',
        'price_max',
        // Backward-compatible aliases.
        'min_price',
        'max_price',
        'vehicle_type',
        // Accepted for forward compatibility (may be implemented later).
        'fleet',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Transfer
    {
        $offer = Offer::query()->findOrFail($data['offer_id'] ?? 0);

        if ($offer->type !== 'transfer') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type transfer.'],
            ]);
        }

        if (Transfer::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['A transfer already exists for this offer.'],
            ]);
        }

        $data = $this->applyTransferPayloadDefaults($data, $offer);

        $rules = $this->transferStoreValidationRules();
        $attrs = $this->runTransferValidator($data, $rules)->validate();
        $this->validateTransferLocationBusinessRules($attrs);
        $attrs = array_merge($attrs, $this->deriveDeprecatedTransferLocationFields(
            (int) $attrs['origin_location_id'],
            (int) $attrs['destination_location_id']
        ));
        $attrs['company_id'] = $offer->company_id;

        return DB::transaction(function () use ($attrs, $offer) {
            $transfer = Transfer::query()->create(
                Arr::only($attrs, (new Transfer)->getFillable())
            );
            $offer->update(['price' => $transfer->base_price]);

            return $transfer->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Transfer $transfer, array $data): Transfer
    {
        $fillable = (new Transfer)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id'], $data['company_id']);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        $createRules = $this->transferStoreValidationRules();
        $partialRules = [];
        foreach (array_keys($data) as $key) {
            if (! isset($createRules[$key])) {
                throw ValidationException::withMessages([
                    $key => ['Unknown or non-updatable field.'],
                ]);
            }
            $rule = array_values(array_filter(
                $createRules[$key],
                fn (mixed $x) => $x !== 'required'
            ));
            array_unshift($rule, 'sometimes');
            $partialRules[$key] = $rule;
        }

        $clean = $this->runTransferValidator($data, $partialRules, $transfer)->validate();
        $this->validateTransferLocationBusinessRules(array_merge([
            'origin_location_id' => $transfer->origin_location_id,
            'destination_location_id' => $transfer->destination_location_id,
        ], $clean));
        $resolvedOriginId = isset($clean['origin_location_id'])
            ? (int) $clean['origin_location_id']
            : (int) $transfer->origin_location_id;
        $resolvedDestinationId = isset($clean['destination_location_id'])
            ? (int) $clean['destination_location_id']
            : (int) $transfer->destination_location_id;
        $clean = array_merge($clean, $this->deriveDeprecatedTransferLocationFields(
            $resolvedOriginId,
            $resolvedDestinationId
        ));

        $basePriceSubmitted = array_key_exists('base_price', $data);

        return DB::transaction(function () use ($transfer, $clean, $data, $basePriceSubmitted): Transfer {
            $transfer->fill(Arr::only($clean, array_keys($data)));
            $transfer->save();

            if ($basePriceSubmitted) {
                $transfer->offer->update(['price' => $transfer->base_price]);
            }

            return $transfer->refresh();
        });
    }

    public function delete(Transfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $transfer->delete();
        });
    }

    /**
     * Parse whitelisted listing filters from an HTTP request query string.
     *
     * @return array<string, mixed>
     */
    public function listingFiltersFromRequest(Request $request): array
    {
        $filters = [];
        foreach (self::LISTING_FILTER_KEYS as $key) {
            if ($request->query->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }

        if (! array_key_exists('vehicle_category', $filters) && $request->query->has('vehicle_type')) {
            $filters['vehicle_category'] = $request->query('vehicle_type');
        }

        return $filters;
    }

    /**
     * Tenant-scoped transfers (parent offer type transfer only), with optional filters and stable ordering.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Transfer>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        $query = $this->baseTenantTransferQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultTransferListOrdering($query);

        return $query->with(['offer'])->get();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateForCompanies(array $companyIds, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->baseTenantTransferQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultTransferListOrdering($query);

        return $query->with(['offer'])->paginate($perPage);
    }

    /**
     * Single transfer in tenant scope with parent offer type transfer.
     *
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int|string $id, array $companyIds): ?Transfer
    {
        if ($companyIds === []) {
            return null;
        }

        return $this->baseTenantTransferQuery($companyIds)
            ->whereKey($id)
            ->with(['offer'])
            ->first();
    }

    /**
     * Resolve a transfer row by id when the parent offer is type transfer (no tenant filter).
     * Used only for write-access edge cases (403 vs 404); do not expose without checks.
     */
    public function findByIdWithTransferOffer(int|string $id): ?Transfer
    {
        return Transfer::query()
            ->whereKey($id)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'transfer');
            })
            ->first();
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function baseTenantTransferQuery(array $companyIds): Builder
    {
        $query = Transfer::query();
        $table = $query->getModel()->getTable();
        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->whereIn($table.'.company_id', $companyIds)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'transfer');
            });
    }

    private function applyDefaultTransferListOrdering(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $query->orderBy($table.'.transfer_title')->orderBy($table.'.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyListingFilters(Builder $query, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        $table = $query->getModel()->getTable();

        if (array_key_exists('company_id', $filters) && $filters['company_id'] !== null && $filters['company_id'] !== '') {
            $query->where($table.'.company_id', (int) $filters['company_id']);
        }

        $locationId = $this->normalizeListingInt($filters['location_id'] ?? null);
        if ($locationId !== null) {
            $query->forLocation($locationId);
        }

        foreach (['status', 'availability_status', 'transfer_type', 'vehicle_category'] as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }
            $value = $filters[$key];
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $query->where($table.'.'.$key, (string) $value);
        }

        if (array_key_exists('is_package_eligible', $filters)) {
            $b = $this->normalizeListingBoolean($filters['is_package_eligible']);
            if ($b !== null) {
                $query->where($table.'.is_package_eligible', $b);
            }
        }

        // Step C3: apply transfer visibility_rule filtering in admin inventory pages.
        // Discovery/public web is handled separately in DiscoveryService.
        $transferVisibilityControlsEnabled = app(PlatformSettingsService::class)->get(
            'transfer_visibility_controls_enabled',
            false
        ) === true;
        $appearanceContext = $filters['appearance_context'] ?? null;
        if ($transferVisibilityControlsEnabled === true && is_string($appearanceContext) && trim($appearanceContext) !== '') {
            $ctx = strtolower(trim($appearanceContext));
            $mappedContext = $ctx === 'web' ? 'web' : 'admin';
            app(OfferVisibilityService::class)->applyVisibilityFilter($query, $mappedContext);

            if ($ctx === 'web') {
                $query->where($table.'.appears_in_web', true);
            } elseif ($ctx === 'zulu_admin' || $ctx === 'zulu-admin') {
                $query->where($table.'.appears_in_zulu_admin', true);
            } else {
                $query->where($table.'.appears_in_admin', true);
            }
        }

        // Deprecated: textual location filters were removed after location tree cutover.

        // Step C2 — trip date (service_date).
        $date = $this->normalizeListingDate($filters['trip_date'] ?? null);
        if ($date !== null) {
            $query->whereDate($table.'.service_date', '=', $date);
        }
        $dateFrom = $this->normalizeListingDate($filters['trip_date_from'] ?? null);
        if ($dateFrom !== null) {
            $query->whereDate($table.'.service_date', '>=', $dateFrom);
        }
        $dateTo = $this->normalizeListingDate($filters['trip_date_to'] ?? null);
        if ($dateTo !== null) {
            $query->whereDate($table.'.service_date', '<=', $dateTo);
        }

        // Step C2 — passenger capacity lower bound.
        $passengersRaw = array_key_exists('passenger', $filters) ? $filters['passenger'] : ($filters['passengers'] ?? null);
        $passengers = $this->normalizeListingInt($passengersRaw);
        if ($passengers !== null) {
            $query->where($table.'.passenger_capacity', '>=', $passengers);
        }

        // Step C2 — price bounds (list anchor: parent offer.price, synced from base_price on write).
        $minPrice = $this->normalizeListingFloat($filters['price_min'] ?? ($filters['min_price'] ?? null));
        $maxPrice = $this->normalizeListingFloat($filters['price_max'] ?? ($filters['max_price'] ?? null));
        $priceExact = $this->normalizeListingFloat($filters['price'] ?? null);
        if ($minPrice !== null || $maxPrice !== null || $priceExact !== null) {
            $query->whereHas('offer', function (Builder $q) use ($minPrice, $maxPrice, $priceExact): void {
                if ($minPrice !== null) {
                    $q->where('price', '>=', $minPrice);
                }
                if ($maxPrice !== null) {
                    $q->where('price', '<=', $maxPrice);
                }
                if ($priceExact !== null) {
                    $q->where('price', '=', $priceExact);
                }
            });
        }

        // Step C2 — booking/invoice filters (safe no-ops if relations absent or values invalid).
        $userEmail = $this->normalizeListingString($filters['user_email'] ?? null);
        if ($userEmail !== null) {
            $like = '%'.addcslashes($userEmail, '%_\\').'%';
            $query->whereHas('offer.bookingItems.booking.user', function (Builder $q) use ($like): void {
                $q->where('email', 'like', $like);
            });
        }

        $invoiceId = $this->normalizeListingInt($filters['invoice_id'] ?? null);
        if ($invoiceId !== null) {
            $query->whereHas('offer.bookingItems.booking.invoices', function (Builder $q) use ($invoiceId): void {
                $q->whereKey($invoiceId);
            });
        }

        $orderNumber = $this->normalizeListingString($filters['order_number'] ?? null);
        if ($orderNumber !== null) {
            $like = '%'.addcslashes($orderNumber, '%_\\').'%';
            $query->whereHas('offer.bookingItems.booking.invoices', function (Builder $q) use ($like): void {
                $q->where('unique_booking_reference', 'like', $like)
                    ->orWhere('vendor_locator', 'like', $like);
            });
        }
    }

    private function normalizeListingBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
            return false;
        }

        return null;
    }

    private function normalizeListingString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function normalizeListingDate(mixed $value): ?string
    {
        $s = $this->normalizeListingString($value);
        if ($s === null) {
            return null;
        }
        // Strict YYYY-MM-DD only.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        if ($dt === false) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    private function normalizeListingInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_numeric($value)) {
            $n = (int) $value;

            return $n > 0 ? $n : null;
        }

        return null;
    }

    private function normalizeListingFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        if (! is_finite($n) || $n < 0) {
            return null;
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyTransferPayloadDefaults(array $data, Offer $offer): array
    {
        $defaults = $this->transferCreateDefaults($offer);
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        if (
            (! array_key_exists('maximum_passengers', $data) || $data['maximum_passengers'] === null)
            && array_key_exists('passenger_capacity', $data)
            && $data['passenger_capacity'] !== null
        ) {
            $data['maximum_passengers'] = (int) $data['passenger_capacity'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transferCreateDefaults(Offer $offer): array
    {
        $offerBaseline = $offer->price !== null ? (float) $offer->price : 0.0;

        return [
            'vehicle_class' => null,
            'private_or_shared' => null,
            'pickup_latitude' => null,
            'pickup_longitude' => null,
            'dropoff_latitude' => null,
            'dropoff_longitude' => null,
            'route_distance_km' => null,
            'route_label' => null,
            'availability_window_start' => null,
            'availability_window_end' => null,
            'maximum_luggage' => null,
            'child_seat_required_rule' => null,
            'cancellation_deadline_at' => null,
            'luggage_capacity' => 0,
            'estimated_duration_minutes' => 60,
            'minimum_passengers' => 1,
            'child_seat_available' => false,
            'accessibility_support' => false,
            'special_assistance_supported' => false,
            'free_cancellation' => false,
            'bookable' => true,
            'availability_status' => 'available',
            'is_package_eligible' => false,
            // Step C3: visibility controls and appearance flags (rollout via platform settings).
            'visibility_rule' => 'show_all',
            'appears_in_web' => true,
            'appears_in_admin' => true,
            'appears_in_zulu_admin' => true,
            'status' => 'draft',
            'cancellation_policy_type' => 'non_refundable',
            'pricing_mode' => 'per_vehicle',
            'base_price' => $offerBaseline,
            'service_date' => now()->toDateString(),
            'pickup_time' => '09:00:00',
        ];
    }

    /**
     * @param  array<string, array<int, string|ValidationRule>>  $rules
     */
    private function runTransferValidator(array $data, array $rules, ?Transfer $existing = null): \Illuminate\Validation\Validator
    {
        $validator = Validator::make($data, $rules);
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($existing): void {
            $d = $v->getData();
            if ($existing !== null) {
                $cap = array_key_exists('passenger_capacity', $d) ? (int) $d['passenger_capacity'] : (int) $existing->passenger_capacity;
                $minP = array_key_exists('minimum_passengers', $d) ? (int) $d['minimum_passengers'] : (int) $existing->minimum_passengers;
                $maxP = array_key_exists('maximum_passengers', $d) ? (int) $d['maximum_passengers'] : (int) $existing->maximum_passengers;
            } else {
                $cap = isset($d['passenger_capacity']) ? (int) $d['passenger_capacity'] : null;
                $minP = isset($d['minimum_passengers']) ? (int) $d['minimum_passengers'] : null;
                $maxP = isset($d['maximum_passengers']) ? (int) $d['maximum_passengers'] : null;
            }
            if ($minP === null || $maxP === null) {
                return;
            }
            if ($minP > $maxP) {
                $v->errors()->add('maximum_passengers', 'Maximum passengers must be greater than or equal to minimum passengers.');
            }
            if ($cap !== null && $maxP > $cap) {
                $v->errors()->add('maximum_passengers', 'Maximum passengers must not exceed passenger capacity.');
            }
        });

        return $validator;
    }

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function transferStoreValidationRules(): array
    {
        $visibilityRules = app(OfferVisibilityService::class)->getVisibilityRules();

        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'visibility_rule' => ['nullable', 'string', Rule::in($visibilityRules)],
            'appears_in_web' => ['boolean'],
            'appears_in_admin' => ['boolean'],
            'appears_in_zulu_admin' => ['boolean'],
            'transfer_title' => ['required', 'string', 'max:255'],
            'transfer_type' => ['required', 'string', Rule::in(Transfer::TRANSFER_TYPES)],
            // Deprecated: legacy text location fields are now derived from origin/destination location IDs.
            'pickup_country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pickup_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'origin_location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'pickup_point_type' => ['required', 'string', Rule::in(Transfer::POINT_TYPES)],
            'pickup_point_name' => ['required', 'string'],
            'dropoff_country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'dropoff_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'dropoff_point_type' => ['required', 'string', Rule::in(Transfer::POINT_TYPES)],
            'dropoff_point_name' => ['required', 'string'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'route_distance_km' => ['nullable', 'numeric', 'min:0'],
            'route_label' => ['nullable', 'string', 'max:255'],
            'service_date' => ['required', 'date'],
            'pickup_time' => ['required', 'date_format:H:i:s'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:1'],
            'availability_window_start' => ['nullable', 'date'],
            'availability_window_end' => ['nullable', 'date'],
            'vehicle_category' => ['required', 'string', Rule::in(Transfer::VEHICLE_CATEGORIES)],
            'vehicle_class' => ['nullable', 'string', 'max:64'],
            'private_or_shared' => ['nullable', 'string', Rule::in(Transfer::PRIVATE_OR_SHARED)],
            'passenger_capacity' => ['required', 'integer', 'min:1'],
            'luggage_capacity' => ['required', 'integer', 'min:0'],
            'child_seat_available' => ['boolean'],
            'accessibility_support' => ['boolean'],
            'minimum_passengers' => ['required', 'integer', 'min:1'],
            'maximum_passengers' => ['required', 'integer', 'min:1'],
            'maximum_luggage' => ['nullable', 'integer', 'min:0'],
            'child_seat_required_rule' => ['nullable', 'string', 'max:64'],
            'special_assistance_supported' => ['boolean'],
            'pricing_mode' => ['required', 'string', Rule::in(Transfer::PRICING_MODES)],
            'base_price' => ['required', 'numeric', 'min:0'],
            'free_cancellation' => ['boolean'],
            'cancellation_policy_type' => ['required', 'string', Rule::in(['non_refundable', 'partially_refundable', 'fully_refundable'])],
            'cancellation_deadline_at' => ['nullable', 'date'],
            'availability_status' => ['required', 'string', 'max:32'],
            'bookable' => ['boolean'],
            'is_package_eligible' => ['boolean'],
            'status' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateTransferLocationBusinessRules(array $payload): void
    {
        $origin = app(LocationBusinessValidator::class)->requireLocationOfTypes(
            isset($payload['origin_location_id']) ? (int) $payload['origin_location_id'] : null,
            'origin_location_id',
            [Location::TYPE_REGION, Location::TYPE_CITY],
            'Transfer origin location is required.',
            'Transfer origin must be region or city.'
        );

        $destination = app(LocationBusinessValidator::class)->requireLocationOfTypes(
            isset($payload['destination_location_id']) ? (int) $payload['destination_location_id'] : null,
            'destination_location_id',
            [Location::TYPE_REGION, Location::TYPE_CITY],
            'Transfer destination location is required.',
            'Transfer destination must be region or city.'
        );

        if ((int) $origin->id === (int) $destination->id) {
            throw ValidationException::withMessages([
                'destination_location_id' => ['Origin and destination must be different locations.'],
            ]);
        }

    }

    /**
     * Legacy columns are kept read-only for rollout safety and are derived from location tree.
     *
     * @return array{pickup_country: string|null, pickup_city: string|null, dropoff_country: string|null, dropoff_city: string|null}
     */
    private function deriveDeprecatedTransferLocationFields(int $originLocationId, int $destinationLocationId): array
    {
        $origin = Location::query()->find($originLocationId);
        $destination = Location::query()->find($destinationLocationId);

        $originLineage = $origin?->ancestors()->push($origin)->values();
        $destinationLineage = $destination?->ancestors()->push($destination)->values();

        return $this->onlyExistingLegacyColumns('transfers', [
            'pickup_country' => optional($originLineage?->firstWhere('type', Location::TYPE_COUNTRY))->name,
            'pickup_city' => optional($originLineage?->firstWhere('type', Location::TYPE_CITY))->name
                ?? optional($originLineage?->firstWhere('type', Location::TYPE_REGION))->name,
            'dropoff_country' => optional($destinationLineage?->firstWhere('type', Location::TYPE_COUNTRY))->name,
            'dropoff_city' => optional($destinationLineage?->firstWhere('type', Location::TYPE_CITY))->name
                ?? optional($destinationLineage?->firstWhere('type', Location::TYPE_REGION))->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function onlyExistingLegacyColumns(string $table, array $payload): array
    {
        $existingKeys = [];
        foreach (array_keys($payload) as $column) {
            if (Schema::hasColumn($table, $column)) {
                $existingKeys[] = $column;
            }
        }

        return Arr::only($payload, $existingKeys);
    }
}
