<?php

namespace App\Services\Cars;

use App\Models\Car;
use App\Models\Offer;
use App\Services\Infrastructure\PlatformSettingsService;
use App\Services\Offers\OfferVisibilityService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CarService
{
    /**
     * Whitelisted query keys for operator/inventory car listing ({@see applyListingFilters}).
     *
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'company_id',
        'vehicle_class',
        'vehicle_type',
        'brand',
        'model',
        'year',
        'transmission_type',
        'fuel_type',
        'fleet',
        'category',
        'seats',
        'pricing_mode',
        'status',
        'availability_status',
        // Step C3 — inventory / operator listing (mapping-aligned).
        'appearance_context',
        'country',
        'city',
        'origin',
        'destination',
        'pickup_location',
        'dropoff_location',
        'date',
        'date_from',
        'date_to',
        'rental_date',
        'rental_date_from',
        'rental_date_to',
        'invoice_id',
        'user_email',
        'base_price_min',
        'base_price_max',
        'min_price',
        'max_price',
        'price_min',
        'price_max',
        'price',
    ];

    /**
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

        return $filters;
    }

    /**
     * Validation rules for {@see CarController::store} (expanded contract).
     *
     * @return array<string, mixed>
     */
    public function carStoreValidationRules(): array
    {
        return array_merge($this->carCoreWriteRules(), $this->carExpandedFieldRules(false));
    }

    /**
     * Validation rules for {@see CarController::update} (partial; expanded fields optional).
     *
     * @return array<string, mixed>
     */
    public function carUpdateValidationRules(): array
    {
        return array_merge([
            'offer_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'pickup_location' => ['sometimes', 'string', 'max:255'],
            'dropoff_location' => ['sometimes', 'string', 'max:255'],
            'vehicle_class' => ['sometimes', 'string', 'max:255'],
        ], $this->carExpandedFieldRules(true));
    }

    /**
     * @return array<string, mixed>
     */
    private function carCoreWriteRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'dropoff_location' => ['required', 'string', 'max:255'],
            'vehicle_class' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function carExpandedFieldRules(bool $partial): array
    {
        $opt = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'vehicle_type' => array_merge($opt, ['string', 'max:255']),
            'brand' => array_merge($opt, ['string', 'max:255']),
            'model' => array_merge($opt, ['string', 'max:255']),
            'year' => array_merge($opt, ['integer', 'min:1900', 'max:2100']),
            'transmission_type' => array_merge($opt, ['string', 'max:64']),
            'fuel_type' => array_merge($opt, ['string', 'max:64']),
            'fleet' => array_merge($opt, ['string', 'max:255']),
            'category' => array_merge($opt, ['string', 'max:255']),
            'seats' => array_merge($opt, ['integer', 'min:1', 'max:255']),
            'suitcases' => array_merge($opt, ['integer', 'min:0', 'max:255']),
            'small_bag' => array_merge($opt, ['integer', 'min:0', 'max:255']),
            'availability_window_start' => array_merge($opt, ['date']),
            'availability_window_end' => array_merge($opt, ['date']),
            'pricing_mode' => array_merge($opt, ['string', Rule::in(Car::PRICING_MODES)]),
            'base_price' => array_merge($opt, ['numeric', 'min:0']),
            'status' => array_merge($opt, ['string', Rule::in(Car::OPERATIONAL_STATUSES)]),
            'availability_status' => array_merge($opt, ['string', Rule::in(Car::AVAILABILITY_STATUSES)]),
            'advanced_options' => array_merge($opt, ['array']),
            'visibility_rule' => array_merge($opt, ['string', Rule::in(app(OfferVisibilityService::class)->getVisibilityRules())]),
            'appears_in_web' => array_merge($opt, ['boolean']),
            'appears_in_admin' => array_merge($opt, ['boolean']),
            'appears_in_zulu_admin' => array_merge($opt, ['boolean']),
        ];
    }

    /**
     * Validation for merged advanced_options v1 shape (after defaults merge).
     * Keys are relative to the `advanced_options` object (wrapped as `advanced_options.*` in {@see applyAdvancedOptions}).
     *
     * @return array<string, mixed>
     */
    private function advancedOptionsMergedValueRules(): array
    {
        return [
            'v' => ['sometimes', 'integer', 'in:1'],
            'child_seats' => ['required', 'array'],
            'child_seats.available' => ['boolean'],
            'child_seats.types' => ['array', 'max:32'],
            'child_seats.types.*' => ['string', Rule::in(CarAdvancedOptionsNormalizer::CHILD_SEAT_TYPES)],
            'extra_luggage' => ['required', 'array'],
            'extra_luggage.additional_suitcases_max' => ['integer', 'min:0', 'max:255'],
            'extra_luggage.additional_small_bags_max' => ['integer', 'min:0', 'max:255'],
            'extra_luggage.notes' => ['nullable', 'string', 'max:500'],
            'services' => ['array', 'max:64'],
            'services.*' => ['string', Rule::in(CarAdvancedOptionsNormalizer::SERVICE_KEYS)],
            'driver_languages' => ['array', 'max:32'],
            'driver_languages.*' => ['string', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
            'pricing_rules' => ['required', 'array'],
            'pricing_rules.mileage' => ['required', 'array'],
            'pricing_rules.mileage.mode' => ['required', 'string', Rule::in(CarAdvancedOptionsNormalizer::MILEAGE_MODES)],
            'pricing_rules.mileage.included_km_per_rental' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'pricing_rules.mileage.extra_km_price' => ['nullable', 'numeric', 'min:0'],
            'pricing_rules.cross_border' => ['required', 'array'],
            'pricing_rules.cross_border.policy' => ['required', 'string', Rule::in(CarAdvancedOptionsNormalizer::CROSS_BORDER_POLICIES)],
            'pricing_rules.cross_border.surcharge_amount' => ['nullable', 'numeric', 'min:0'],
            'pricing_rules.radius' => ['required', 'array'],
            'pricing_rules.radius.service_radius_km' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'pricing_rules.radius.out_of_radius_mode' => ['required', 'string', Rule::in(CarAdvancedOptionsNormalizer::OUT_OF_RADIUS_MODES)],
            'pricing_rules.radius.out_of_radius_flat_fee' => ['nullable', 'numeric', 'min:0'],
            'pricing_rules.radius.out_of_radius_per_km' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function advancedOptionsRequestRules(): array
    {
        $inner = $this->advancedOptionsMergedValueRules();
        $out = [];
        foreach ($inner as $k => $v) {
            $out['advanced_options.'.$k] = $v;
        }

        return $out;
    }

    /**
     * Reject contradictory Step C2 keys in the incoming patch before merge (merge coercions would hide them).
     *
     * @param  array<string, mixed>  $rawAdvancedOptions
     */
    private function validatePricingRulesIncomingPatch(array $rawAdvancedOptions): void
    {
        $pr = $rawAdvancedOptions['pricing_rules'] ?? null;
        if (! is_array($pr)) {
            return;
        }
        $mileage = $pr['mileage'] ?? null;
        if (is_array($mileage) && ($mileage['mode'] ?? null) === 'unlimited') {
            if (array_key_exists('included_km_per_rental', $mileage) && $mileage['included_km_per_rental'] !== null && $mileage['included_km_per_rental'] !== '') {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.mileage.included_km_per_rental' => ['Remove included km when mileage mode is unlimited.'],
                ]);
            }
            if (array_key_exists('extra_km_price', $mileage) && $mileage['extra_km_price'] !== null && $mileage['extra_km_price'] !== '') {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.mileage.extra_km_price' => ['Remove extra km price when mileage mode is unlimited.'],
                ]);
            }
        }

        $rad = $pr['radius'] ?? null;
        if (! is_array($rad)) {
            return;
        }
        $sr = $rad['service_radius_km'] ?? null;
        $radiusKm = ($sr !== null && $sr !== '') ? (int) $sr : 0;
        $orm = $rad['out_of_radius_mode'] ?? null;
        if ($radiusKm <= 0 || ! is_string($orm) || $orm === '') {
            return;
        }
        $flatRaw = $rad['out_of_radius_flat_fee'] ?? null;
        $flatVal = ($flatRaw !== null && $flatRaw !== '') ? (float) $flatRaw : null;
        $perKmRaw = $rad['out_of_radius_per_km'] ?? null;
        $perKmVal = ($perKmRaw !== null && $perKmRaw !== '') ? (float) $perKmRaw : null;
        if ($orm !== 'flat_fee' && $flatVal !== null && $flatVal > 0) {
            throw ValidationException::withMessages([
                'advanced_options.pricing_rules.radius.out_of_radius_flat_fee' => ['Remove the flat fee unless out-of-radius mode is flat_fee.'],
            ]);
        }
        if ($orm !== 'per_km' && $perKmVal !== null && $perKmVal > 0) {
            throw ValidationException::withMessages([
                'advanced_options.pricing_rules.radius.out_of_radius_per_km' => ['Remove the per-km price unless out-of-radius mode is per_km.'],
            ]);
        }
    }

    /**
     * Cross-field rules for Step C2 pricing_rules (mileage / cross-border / radius).
     *
     * @param  array<string, mixed>  $pricingRules
     */
    private function validatePricingRulesSemantics(array $pricingRules): void
    {
        $mileage = is_array($pricingRules['mileage'] ?? null) ? $pricingRules['mileage'] : [];
        $mode = $mileage['mode'] ?? 'unlimited';

        if ($mode === 'limited') {
            if (! isset($mileage['included_km_per_rental']) || $mileage['included_km_per_rental'] === '' || (int) $mileage['included_km_per_rental'] < 1) {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.mileage.included_km_per_rental' => ['Included km is required when mileage mode is limited.'],
                ]);
            }
        } else {
            if (isset($mileage['included_km_per_rental']) && $mileage['included_km_per_rental'] !== null && $mileage['included_km_per_rental'] !== '') {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.mileage.included_km_per_rental' => ['Remove included km when mileage mode is unlimited.'],
                ]);
            }
            if (isset($mileage['extra_km_price']) && $mileage['extra_km_price'] !== null && $mileage['extra_km_price'] !== '') {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.mileage.extra_km_price' => ['Remove extra km price when mileage mode is unlimited.'],
                ]);
            }
        }

        $cb = is_array($pricingRules['cross_border'] ?? null) ? $pricingRules['cross_border'] : [];
        $pol = $cb['policy'] ?? 'not_allowed';
        if (in_array($pol, ['surcharge_fixed', 'surcharge_daily'], true)) {
            if (! isset($cb['surcharge_amount']) || $cb['surcharge_amount'] === '' || (float) $cb['surcharge_amount'] <= 0) {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.cross_border.surcharge_amount' => ['Surcharge amount is required and must be greater than 0 for this cross-border policy.'],
                ]);
            }
        } else {
            if (isset($cb['surcharge_amount']) && $cb['surcharge_amount'] !== null && $cb['surcharge_amount'] !== '' && (float) $cb['surcharge_amount'] > 0) {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.cross_border.surcharge_amount' => ['Surcharge amount must be empty unless a surcharge policy is selected.'],
                ]);
            }
        }

        $rad = is_array($pricingRules['radius'] ?? null) ? $pricingRules['radius'] : [];
        $sr = $rad['service_radius_km'] ?? null;
        $radiusKm = ($sr !== null && $sr !== '') ? (int) $sr : 0;
        $orm = $rad['out_of_radius_mode'] ?? 'not_applicable';

        if ($radiusKm <= 0) {
            if ($orm !== 'not_applicable') {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.radius.out_of_radius_mode' => ['Set a service radius (km) before choosing out-of-radius pricing, or use not_applicable when no radius applies.'],
                ]);
            }
            if (isset($rad['out_of_radius_flat_fee']) && $rad['out_of_radius_flat_fee'] !== null && $rad['out_of_radius_flat_fee'] !== '' && (float) $rad['out_of_radius_flat_fee'] > 0) {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.radius.out_of_radius_flat_fee' => ['Out-of-radius fees require a positive service radius.'],
                ]);
            }
            if (isset($rad['out_of_radius_per_km']) && $rad['out_of_radius_per_km'] !== null && $rad['out_of_radius_per_km'] !== '' && (float) $rad['out_of_radius_per_km'] > 0) {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.radius.out_of_radius_per_km' => ['Out-of-radius per-km price requires a positive service radius.'],
                ]);
            }
        } else {
            if ($orm === 'not_applicable') {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.radius.out_of_radius_mode' => ['Choose how out-of-radius trips are priced when a service radius is set.'],
                ]);
            }
            $flatRaw = $rad['out_of_radius_flat_fee'] ?? null;
            $flatVal = ($flatRaw !== null && $flatRaw !== '') ? (float) $flatRaw : null;
            $perKmRaw = $rad['out_of_radius_per_km'] ?? null;
            $perKmVal = ($perKmRaw !== null && $perKmRaw !== '') ? (float) $perKmRaw : null;

            if ($orm !== 'flat_fee' && $flatVal !== null && $flatVal > 0) {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.radius.out_of_radius_flat_fee' => ['Remove the flat fee unless out-of-radius mode is flat_fee.'],
                ]);
            }
            if ($orm !== 'per_km' && $perKmVal !== null && $perKmVal > 0) {
                throw ValidationException::withMessages([
                    'advanced_options.pricing_rules.radius.out_of_radius_per_km' => ['Remove the per-km price unless out-of-radius mode is per_km.'],
                ]);
            }

            if ($orm === 'flat_fee') {
                if (! isset($rad['out_of_radius_flat_fee']) || $rad['out_of_radius_flat_fee'] === '' || (float) $rad['out_of_radius_flat_fee'] < 0) {
                    throw ValidationException::withMessages([
                        'advanced_options.pricing_rules.radius.out_of_radius_flat_fee' => ['Flat fee is required (≥ 0) when out-of-radius mode is flat_fee.'],
                    ]);
                }
            }
            if ($orm === 'per_km') {
                if (! isset($rad['out_of_radius_per_km']) || $rad['out_of_radius_per_km'] === '' || (float) $rad['out_of_radius_per_km'] < 0) {
                    throw ValidationException::withMessages([
                        'advanced_options.pricing_rules.radius.out_of_radius_per_km' => ['Per-km price is required (≥ 0) when out-of-radius mode is per_km.'],
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload  Fillable slice
     * @param  array<string, mixed>  $fullRequest
     * @return array<string, mixed>
     */
    private function applyAdvancedOptions(array $payload, array $fullRequest, ?Car $car): array
    {
        if (! array_key_exists('advanced_options', $fullRequest)) {
            return $payload;
        }

        $norm = app(CarAdvancedOptionsNormalizer::class);
        $raw = $fullRequest['advanced_options'];
        if ($raw === null) {
            $payload['advanced_options'] = null;

            return $payload;
        }
        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'advanced_options' => ['Advanced options must be an object or null.'],
            ]);
        }

        $this->validatePricingRulesIncomingPatch($raw);

        $merged = $norm->mergeForUpdate($car?->advanced_options, $raw);
        Validator::make(['advanced_options' => $merged], $this->advancedOptionsRequestRules())->validate();
        $this->validatePricingRulesSemantics(is_array($merged['pricing_rules'] ?? null) ? $merged['pricing_rules'] : []);
        $payload['advanced_options'] = $norm->normalizeForStorage($merged);

        return $payload;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Car>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        $query = $this->baseTenantCarQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultListOrdering($query);

        return $query->with(['offer'])->get();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateForCompanies(array $companyIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->baseTenantCarQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultListOrdering($query);

        return $query->with(['offer'])->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int|string $id, array $companyIds): ?Car
    {
        if ($companyIds === []) {
            return null;
        }

        return $this->baseTenantCarQuery($companyIds)
            ->whereKey($id)
            ->with(['offer'])
            ->first();
    }

    public function findByIdWithCarOffer(int|string $id): ?Car
    {
        return Car::query()
            ->whereKey($id)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'car');
            })
            ->with(['offer'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data  Must include offer_id; ownership is from the offer (offer.company_id). Optional company_id, if sent, must match the offer (not stored on cars).
     */
    public function create(array $data): Car
    {
        $offer = Offer::query()->findOrFail((int) ($data['offer_id'] ?? 0));

        if ($offer->type !== 'car') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type car.'],
            ]);
        }

        if (isset($data['company_id']) && (int) $data['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        if (Car::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['A car already exists for this offer.'],
            ]);
        }

        $incoming = $data;
        $fillable = (new Car)->getFillable();
        $payload = Arr::only($data, $fillable);
        $payload = $this->applyAdvancedOptions($payload, $incoming, null);
        $this->assertAvailabilityWindowOrder($payload['availability_window_start'] ?? null, $payload['availability_window_end'] ?? null);

        return DB::transaction(function () use ($payload, $offer): Car {
            $car = Car::query()->create($payload);
            if ($car->base_price !== null) {
                $offer->update(['price' => $car->base_price]);
            }

            return $car->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Car $car, array $data): Car
    {
        $incoming = $data;
        $fillable = (new Car)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id']);
        $data = $this->applyAdvancedOptions($data, $incoming, $car);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        $start = array_key_exists('availability_window_start', $data)
            ? $data['availability_window_start']
            : $car->availability_window_start;
        $end = array_key_exists('availability_window_end', $data)
            ? $data['availability_window_end']
            : $car->availability_window_end;
        $this->assertAvailabilityWindowOrder($start, $end);

        $basePriceSubmitted = array_key_exists('base_price', $data);

        return DB::transaction(function () use ($car, $data, $basePriceSubmitted): Car {
            $car->fill($data);
            $car->save();

            if ($basePriceSubmitted && $car->base_price !== null) {
                $car->offer->update(['price' => $car->base_price]);
            }

            return $car->refresh();
        });
    }

    public function delete(Car $car): void
    {
        DB::transaction(fn () => $car->delete());
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function baseTenantCarQuery(array $companyIds): Builder
    {
        $query = Car::query();
        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('offer', function (Builder $q) use ($companyIds): void {
            $q->where('type', 'car')
                ->whereIn('company_id', $companyIds);
        });
    }

    private function applyDefaultListOrdering(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $query->orderBy($table.'.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyListingFilters(Builder $query, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        // Mapping-aligned price aliases (backward compatible).
        if (array_key_exists('min_price', $filters)
            && ($filters['min_price'] !== null && $filters['min_price'] !== '')
            && (! array_key_exists('base_price_min', $filters) || $filters['base_price_min'] === null || $filters['base_price_min'] === '')
        ) {
            $filters['base_price_min'] = $filters['min_price'];
        }

        if (array_key_exists('max_price', $filters)
            && ($filters['max_price'] !== null && $filters['max_price'] !== '')
            && (! array_key_exists('base_price_max', $filters) || $filters['base_price_max'] === null || $filters['base_price_max'] === '')
        ) {
            $filters['base_price_max'] = $filters['max_price'];
        }

        if (array_key_exists('price_min', $filters)
            && ($filters['price_min'] !== null && $filters['price_min'] !== '')
            && (! array_key_exists('base_price_min', $filters) || $filters['base_price_min'] === null || $filters['base_price_min'] === '')
        ) {
            $filters['base_price_min'] = $filters['price_min'];
        }

        if (array_key_exists('price_max', $filters)
            && ($filters['price_max'] !== null && $filters['price_max'] !== '')
            && (! array_key_exists('base_price_max', $filters) || $filters['base_price_max'] === null || $filters['base_price_max'] === '')
        ) {
            $filters['base_price_max'] = $filters['price_max'];
        }

        if (array_key_exists('company_id', $filters) && $filters['company_id'] !== null && $filters['company_id'] !== '') {
            $companyId = (int) $filters['company_id'];
            $query->whereHas('offer', function (Builder $q) use ($companyId): void {
                $q->where('company_id', $companyId);
            });
        }

        $table = $query->getModel()->getTable();

        // Step C3: visibility_rule + appearance flags (rollout via platform settings).
        $carVisibilityControlsEnabled = app(PlatformSettingsService::class)->get(
            'car_visibility_controls_enabled',
            false
        ) === true;
        $appearanceContext = $filters['appearance_context'] ?? null;
        if ($carVisibilityControlsEnabled === true && is_string($appearanceContext) && trim($appearanceContext) !== '') {
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

        if (array_key_exists('vehicle_class', $filters)) {
            $value = $filters['vehicle_class'];
            if ($value !== null && $value !== '' && (is_string($value) || is_numeric($value))) {
                $query->where($table.'.vehicle_class', (string) $value);
            }
        }

        $this->applyOptionalStringColumnFilter($query, $table, 'vehicle_type', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'brand', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'model', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'transmission_type', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'fuel_type', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'fleet', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'category', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'pricing_mode', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'status', $filters);
        $this->applyOptionalStringColumnFilter($query, $table, 'availability_status', $filters);

        if (array_key_exists('year', $filters) && $filters['year'] !== null && $filters['year'] !== '') {
            $year = (int) $filters['year'];
            if ($year > 0) {
                $query->where($table.'.year', $year);
            }
        }

        if (array_key_exists('seats', $filters) && $filters['seats'] !== null && $filters['seats'] !== '') {
            $seats = (int) $filters['seats'];
            if ($seats > 0) {
                $query->where($table.'.seats', $seats);
            }
        }

        // Location-ish filters (substring; free-text pickup/dropoff locations).
        $country = $this->normalizeListingString($filters['country'] ?? null);
        if ($country !== null) {
            $like = '%'.addcslashes($country, '%_\\').'%';
            $query->where(function (Builder $q) use ($table, $like): void {
                $q->where($table.'.pickup_location', 'like', $like)
                    ->orWhere($table.'.dropoff_location', 'like', $like);
            });
        }

        $city = $this->normalizeListingString($filters['city'] ?? null);
        if ($city !== null) {
            $like = '%'.addcslashes($city, '%_\\').'%';
            $query->where(function (Builder $q) use ($table, $like): void {
                $q->where($table.'.pickup_location', 'like', $like)
                    ->orWhere($table.'.dropoff_location', 'like', $like);
            });
        }

        $origin = $this->normalizeListingString($filters['origin'] ?? null);
        if ($origin === null) {
            $origin = $this->normalizeListingString($filters['pickup_location'] ?? null);
        }
        if ($origin !== null) {
            $like = '%'.addcslashes($origin, '%_\\').'%';
            $query->where($table.'.pickup_location', 'like', $like);
        }

        $destination = $this->normalizeListingString($filters['destination'] ?? null);
        if ($destination === null) {
            $destination = $this->normalizeListingString($filters['dropoff_location'] ?? null);
        }
        if ($destination !== null) {
            $like = '%'.addcslashes($destination, '%_\\').'%';
            $query->where($table.'.dropoff_location', 'like', $like);
        }

        $this->applyCarRentalAvailabilityDateFilters($query, $table, $filters);

        // List anchor: parent offer.price (aliases base_price_min/max still accepted; synced from cars.base_price on write).
        $minPrice = $this->normalizeListingFloat($filters['base_price_min'] ?? null);
        $maxPrice = $this->normalizeListingFloat($filters['base_price_max'] ?? null);
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

        $hasCheckIn = Schema::hasColumn('invoices', 'check_in');
        $hasCheckOut = Schema::hasColumn('invoices', 'check_out');

        $parseDate = function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            try {
                return Carbon::parse((string) $v)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };

        $date = array_key_exists('date', $filters) ? $parseDate($filters['date']) : null;
        $dateFrom = array_key_exists('date_from', $filters) ? $parseDate($filters['date_from']) : null;
        $dateTo = array_key_exists('date_to', $filters) ? $parseDate($filters['date_to']) : null;

        $applyCheckIn = ($date !== null || $dateFrom !== null) && $hasCheckIn;
        $applyCheckOut = $dateTo !== null && $hasCheckOut;

        if ($applyCheckIn || $applyCheckOut) {
            $query->whereHas('offer.bookingItems.booking.invoices', function (Builder $iq) use ($date, $dateFrom, $dateTo, $hasCheckIn, $hasCheckOut): void {
                if ($date !== null && $hasCheckIn) {
                    $iq->whereDate('check_in', $date);
                }
                if ($dateFrom !== null && $hasCheckIn) {
                    $iq->whereDate('check_in', '>=', $dateFrom);
                }
                if ($dateTo !== null && $hasCheckOut) {
                    $iq->whereDate('check_out', '<=', $dateTo);
                }
            });
        }
    }

    /**
     * Availability window overlap on cars (`rental_date` / `rental_date_from` / `rental_date_to`).
     * Invoice-oriented `date` / `date_from` / `date_to` are handled separately (hotel-aligned).
     */
    private function applyCarRentalAvailabilityDateFilters(Builder $query, string $table, array $filters): void
    {
        $d = $this->normalizeListingDate($filters['rental_date'] ?? null);
        $from = $this->normalizeListingDate($filters['rental_date_from'] ?? null);
        $to = $this->normalizeListingDate($filters['rental_date_to'] ?? null);

        if ($d !== null) {
            $query->where(function (Builder $q) use ($table, $d): void {
                $q->where(function (Builder $q2) use ($table, $d): void {
                    $q2->whereNull($table.'.availability_window_start')
                        ->orWhereDate($table.'.availability_window_start', '<=', $d);
                })->where(function (Builder $q2) use ($table, $d): void {
                    $q2->whereNull($table.'.availability_window_end')
                        ->orWhereDate($table.'.availability_window_end', '>=', $d);
                });
            });

            return;
        }

        if ($from !== null || $to !== null) {
            $fromBound = $from ?? '1970-01-01';
            $toBound = $to ?? '2999-12-31';
            $query->where(function (Builder $q) use ($table, $fromBound, $toBound): void {
                $q->where(function (Builder $q2) use ($table, $toBound): void {
                    $q2->whereNull($table.'.availability_window_start')
                        ->orWhereDate($table.'.availability_window_start', '<=', $toBound);
                })->where(function (Builder $q2) use ($table, $fromBound): void {
                    $q2->whereNull($table.'.availability_window_end')
                        ->orWhereDate($table.'.availability_window_end', '>=', $fromBound);
                });
            });
        }
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
        if (is_numeric($value)) {
            $f = (float) $value;

            return is_finite($f) ? $f : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyOptionalStringColumnFilter(Builder $query, string $table, string $column, array $filters): void
    {
        if (! array_key_exists($column, $filters)) {
            return;
        }

        $value = $filters[$column];
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return;
        }

        $query->where($table.'.'.$column, (string) $value);
    }

    private function assertAvailabilityWindowOrder(mixed $start, mixed $end): void
    {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return;
        }

        $s = $start instanceof \DateTimeInterface ? $start : new \DateTimeImmutable((string) $start);
        $e = $end instanceof \DateTimeInterface ? $end : new \DateTimeImmutable((string) $end);

        if ($e < $s) {
            throw ValidationException::withMessages([
                'availability_window_end' => ['The availability end must be on or after the start.'],
            ]);
        }
    }
}
