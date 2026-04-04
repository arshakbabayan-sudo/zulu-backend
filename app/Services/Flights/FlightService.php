<?php

namespace App\Services\Flights;

use App\Models\Flight;
use App\Models\FlightCabin;
use App\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;

class FlightService
{
    /**
     * Whitelisted query keys for operator flight listing ({@see applyFilters}).
     *
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'country',
        'city',
        'airport',
        'airline',
        'date',
        'date_from',
        'date_to',
        'transit',
        'class',
        'carry_on',
        'cancellation',
        'registration',
        'reservation',
        'quantity',
        'invoice_id',
        'user_email',
        'departure_country',
        'departure_city',
        'departure_airport',
        'departure_airport_code',
        'arrival_country',
        'arrival_city',
        'arrival_airport',
        'arrival_airport_code',
        'departure_at_from',
        'departure_at_to',
        'arrival_at_from',
        'arrival_at_to',
        'connection_type',
        'stops_count_max',
        'cabin_class',
        'fare_family',
        'is_package_eligible',
        'status',
        'company_id',
        'service_type',
        'min_price',
        'max_price',
        'price_min',
        'price_max',
        'only_active_flights',
        'only_published_offers',
        'appearance_context',
    ];

    /**
     * Create a flight row for one offer (one-to-one). {@see Flight} for allowed enum values.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Flight
    {
        $offer = Offer::query()->findOrFail($data['offer_id']);

        if ($offer->type !== 'flight') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type flight.'],
            ]);
        }

        if (Flight::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['A flight already exists for this offer.'],
            ]);
        }

        $defaults = [
            'departure_airport_code' => null,
            'arrival_airport_code' => null,
            'departure_terminal' => null,
            'arrival_terminal' => null,
            'timezone_context' => null,
            'check_in_close_at' => null,
            'boarding_close_at' => null,
            'connection_notes' => null,
            'layover_summary' => null,
            'fare_family' => null,
            'seat_map_available' => false,
            'seat_selection_policy' => null,
            'hand_baggage_weight' => null,
            'checked_baggage_weight' => null,
            'baggage_notes' => null,
            'reservation_deadline_at' => null,
            'cancellation_deadline_at' => null,
            'change_deadline_at' => null,
            'policy_notes' => null,
            'extra_baggage_allowed' => false,
            'is_package_eligible' => true,
            'appears_in_web' => true,
            'appears_in_admin' => true,
            'appears_in_zulu_admin' => true,
            'child_price' => 0,
            'infant_price' => 0,
        ];

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $v = Validator::make($data, $this->flightStoreValidationRules());
        $v->after(function (\Illuminate\Validation\Validator $validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $departure = Carbon::parse($data['departure_at']);
            $arrival = Carbon::parse($data['arrival_at']);
            if ($arrival->lte($departure)) {
                $validator->errors()->add('arrival_at', 'Arrival must be after departure.');
            }
        });

        $clean = $v->validate();
        $clean['company_id'] = $offer->company_id;

        $payload = Arr::only($clean, (new Flight)->getFillable());

        return DB::transaction(function () use ($payload) {
            $flight = Flight::query()->create($payload);
            $this->syncOfferPriceForFlight($flight);

            return $flight->fresh();
        });
    }

    /**
     * Partial update of flight module fields only (offer_id and company_id are ignored).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Flight $flight, array $data): Flight
    {
        $fillable = (new Flight)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id'], $data['company_id']);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        $adultPriceSubmitted = array_key_exists('adult_price', $data);

        $createRules = $this->flightStoreValidationRules();
        $partialRules = [];
        foreach (array_keys($data) as $key) {
            if (! isset($createRules[$key])) {
                throw ValidationException::withMessages([
                    $key => ['Unknown or non-updatable field.'],
                ]);
            }
            if ($key === 'adult_price') {
                $partialRules[$key] = ['sometimes', 'numeric', 'min:0'];

                continue;
            }
            $rule = array_values(array_filter(
                $createRules[$key],
                fn (mixed $x) => $x !== 'required'
            ));
            array_unshift($rule, 'sometimes');
            $partialRules[$key] = $rule;
        }

        $v = Validator::make($data, $partialRules);
        $v->after(function (\Illuminate\Validation\Validator $validator) use ($flight, $data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $departure = isset($data['departure_at'])
                ? Carbon::parse($data['departure_at'])
                : $flight->departure_at;
            $arrival = isset($data['arrival_at'])
                ? Carbon::parse($data['arrival_at'])
                : $flight->arrival_at;
            if ($departure === null || $arrival === null) {
                return;
            }
            if ($arrival->lte($departure)) {
                $validator->errors()->add('arrival_at', 'Arrival must be after departure.');
            }

            $status = $data['status'] ?? $flight->status;
            $adultPrice = $data['adult_price'] ?? $flight->adult_price;
            if ($status === 'active' && (float) $adultPrice <= 0) {
                $validator->errors()->add(
                    'adult_price',
                    'Active flights must have a positive adult price.'
                );
            }
        });

        $clean = $v->validate();

        DB::transaction(function () use ($flight, $clean, $data, $adultPriceSubmitted): void {
            $flight->fill(Arr::only($clean, array_keys($data)));
            $flight->save();

            if ($adultPriceSubmitted && ! FlightCabin::query()->where('flight_id', $flight->id)->exists()) {
                $this->syncOfferPriceForFlight($flight->refresh());
            }
        });

        return $flight->refresh();
    }

    public function delete(Flight $flight): void
    {
        $flight->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addCabin(Flight $flight, array $data): FlightCabin
    {
        $clean = Validator::make($data, $this->cabinStoreValidationRules())->validate();

        if (FlightCabin::query()
            ->where('flight_id', $flight->id)
            ->where('cabin_class', $clean['cabin_class'])
            ->exists()) {
            throw ValidationException::withMessages([
                'cabin_class' => ['This cabin class already exists for this flight.'],
            ]);
        }

        $payload = Arr::only($clean, (new FlightCabin)->getFillable());
        $payload['flight_id'] = $flight->id;

        return DB::transaction(function () use ($payload, $flight) {
            $cabin = FlightCabin::query()->create($payload)->fresh();
            $this->syncOfferPriceForFlight($flight->refresh());

            return $cabin;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCabin(FlightCabin $cabin, array $data): FlightCabin
    {
        $data = Arr::except($data, ['cabin_class', 'flight_id']);
        $fillable = (new FlightCabin)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['cabin_class'], $data['flight_id']);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        $baseRules = $this->cabinStoreValidationRules();
        $partialRules = [];
        foreach (array_keys($data) as $key) {
            if (! isset($baseRules[$key])) {
                throw ValidationException::withMessages([
                    $key => ['Unknown or non-updatable field.'],
                ]);
            }
            $rule = array_values(array_filter(
                $baseRules[$key],
                fn (mixed $x) => $x !== 'required'
            ));
            array_unshift($rule, 'sometimes');
            $partialRules[$key] = $rule;
        }

        $clean = Validator::make($data, $partialRules)->validate();
        $cabin->fill(Arr::only($clean, array_keys($clean)));
        $cabin->save();

        if ($cabin->wasChanged('adult_price')) {
            $this->syncOfferPriceForFlight($cabin->flight->refresh());
        }

        return $cabin->fresh();
    }

    public function deleteCabin(FlightCabin $cabin): void
    {
        $flight = $cabin->flight;
        $cabin->delete();
        $this->syncOfferPriceForFlight($flight->refresh());
    }

    /**
     * Cheapest sellable adult price for the flight offer: MIN(cabin adult_price) when any cabin exists,
     * otherwise {@see Flight::$adult_price}.
     */
    private function computeFlightOfferPrice(Flight $flight): string
    {
        $minCabin = FlightCabin::query()
            ->where('flight_id', $flight->id)
            ->min('adult_price');

        if ($minCabin !== null) {
            return (string) $minCabin;
        }

        return (string) $flight->adult_price;
    }

    private function syncOfferPriceForFlight(Flight $flight): void
    {
        $offer = $flight->offer;
        if ($offer === null) {
            return;
        }

        $offer->update(['price' => $this->computeFlightOfferPrice($flight)]);
    }

    public function listCabins(Flight $flight): Collection
    {
        return $flight->cabins()->get();
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function cabinStoreValidationRules(): array
    {
        return [
            'cabin_class' => ['required', 'string', Rule::in(FlightCabin::CABIN_CLASSES)],
            'seat_capacity_total' => ['required', 'integer', 'min:0'],
            'seat_capacity_available' => ['required', 'integer', 'min:0'],
            'adult_price' => ['required', 'numeric', 'gt:0'],
            'child_price' => ['required', 'numeric', 'min:0'],
            'infant_price' => ['required', 'numeric', 'min:0'],
            'hand_baggage_included' => ['required', 'boolean'],
            'hand_baggage_weight' => ['nullable', 'string', 'max:32'],
            'checked_baggage_included' => ['required', 'boolean'],
            'checked_baggage_weight' => ['nullable', 'string', 'max:32'],
            'extra_baggage_allowed' => ['required', 'boolean'],
            'baggage_notes' => ['nullable', 'string'],
            'fare_family' => ['nullable', 'string', 'max:100'],
            'seat_map_available' => ['required', 'boolean'],
            'seat_selection_policy' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Base query for flights with optional listing filters (unknown keys ignored).
     * Price bounds use {@see Offer::$price} via the offer relation, not {@see Flight::$adult_price}.
     *
     * Optional safety flags (not applied unless truthy):
     * - only_active_flights: restrict to flight status `active`
     * - only_published_offers: restrict to offers with status `published`
     *
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters = []): Builder
    {
        $query = Flight::query();
        $this->applyFilters($query, $filters);

        return $query;
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

        return $filters;
    }

    /**
     * Tenant-scoped flights for operator listing, with filters and stable ordering.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Flight>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        $query = $this->tenantScopedFlightQuery($companyIds);
        $this->applyFilters($query, $filters);
        $this->applyAppearanceContextConstraints($query, $filters['appearance_context'] ?? null);
        $this->applyDefaultFlightListOrdering($query);

        return $query->with(['offer', 'company'])->get();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateForCompanies(array $companyIds, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->tenantScopedFlightQuery($companyIds);
        $this->applyFilters($query, $filters);
        $this->applyAppearanceContextConstraints($query, $filters['appearance_context'] ?? null);
        $this->applyDefaultFlightListOrdering($query);

        return $query->with(['offer', 'company'])->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function tenantScopedFlightQuery(array $companyIds): Builder
    {
        $query = Flight::query();
        $table = $query->getModel()->getTable();
        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn($table.'.company_id', $companyIds);
    }

    private function applyDefaultFlightListOrdering(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $query->orderBy($table.'.departure_at')->orderBy($table.'.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        if ($this->normalizeListingBoolean($filters['only_active_flights'] ?? null) === true) {
            $query->where($query->getModel()->getTable().'.status', 'active');
        }

        $publishedOnly = $this->normalizeListingBoolean($filters['only_published_offers'] ?? null) === true;
        $minPrice = $this->nullableListingNumeric($filters['min_price'] ?? $filters['price_min'] ?? null);
        $maxPrice = $this->nullableListingNumeric($filters['max_price'] ?? $filters['price_max'] ?? null);
        if ($publishedOnly || $minPrice !== null || $maxPrice !== null) {
            $query->whereHas('offer', function (Builder $q) use ($publishedOnly, $minPrice, $maxPrice): void {
                if ($publishedOnly) {
                    $q->where('status', Offer::STATUS_PUBLISHED);
                }
                if ($minPrice !== null) {
                    $q->where('price', '>=', $minPrice);
                }
                if ($maxPrice !== null) {
                    $q->where('price', '<=', $maxPrice);
                }
            });
        }

        $table = $query->getModel()->getTable();

        foreach (['country', 'city', 'airport'] as $routeFilterKey) {
            if (! array_key_exists($routeFilterKey, $filters)) {
                continue;
            }

            $value = $filters[$routeFilterKey];
            if ($value === null || $value === '' || (! is_string($value) && ! is_numeric($value))) {
                continue;
            }

            $needle = trim((string) $value);
            if ($needle === '') {
                continue;
            }

            $columnStem = $routeFilterKey;
            $safeNeedle = '%'.addcslashes($needle, '%_\\').'%';
            $query->where(function (Builder $q) use ($table, $columnStem, $safeNeedle): void {
                $q->where($table.'.departure_'.$columnStem, 'like', $safeNeedle)
                    ->orWhere($table.'.arrival_'.$columnStem, 'like', $safeNeedle);
            });
        }

        if (array_key_exists('airline', $filters)) {
            $airline = $filters['airline'];
            if ($airline !== null && $airline !== '' && (is_string($airline) || is_numeric($airline))) {
                $needle = trim((string) $airline);
                if ($needle !== '') {
                    $safeNeedle = '%'.addcslashes($needle, '%_\\').'%';
                    $query->whereHas('offer', function (Builder $q) use ($safeNeedle): void {
                        // Safe fallback until a dedicated airline field is introduced.
                        $q->where('title', 'like', $safeNeedle);
                    });
                }
            }
        }

        $stringEquals = [
            'departure_country' => 'departure_country',
            'departure_city' => 'departure_city',
            'departure_airport' => 'departure_airport',
            'departure_airport_code' => 'departure_airport_code',
            'arrival_country' => 'arrival_country',
            'arrival_city' => 'arrival_city',
            'arrival_airport' => 'arrival_airport',
            'arrival_airport_code' => 'arrival_airport_code',
            'connection_type' => 'connection_type',
            'cabin_class' => 'cabin_class',
            'fare_family' => 'fare_family',
            'status' => 'status',
            'service_type' => 'service_type',
        ];

        foreach ($stringEquals as $filterKey => $column) {
            if (! array_key_exists($filterKey, $filters)) {
                continue;
            }
            $value = $filters[$filterKey];
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $value = (string) $value;
            if ($filterKey === 'connection_type' && ! in_array($value, Flight::CONNECTION_TYPES, true)) {
                continue;
            }
            if ($filterKey === 'cabin_class' && ! in_array($value, Flight::CABIN_CLASSES, true)) {
                continue;
            }
            if ($filterKey === 'status' && ! in_array($value, Flight::STATUSES, true)) {
                continue;
            }
            if ($filterKey === 'service_type' && ! in_array($value, Flight::SERVICE_TYPES, true)) {
                continue;
            }
            $query->where($query->getModel()->getTable().'.'.$column, $value);
        }

        if (array_key_exists('class', $filters)) {
            $value = $filters['class'];
            if ($value !== null && $value !== '' && (is_string($value) || is_numeric($value))) {
                $value = (string) $value;
                if (in_array($value, Flight::CABIN_CLASSES, true)) {
                    $query->where($table.'.cabin_class', $value);
                }
            }
        }

        if (array_key_exists('company_id', $filters)) {
            $cid = $filters['company_id'];
            if ($cid !== null && $cid !== '' && is_numeric($cid)) {
                $query->where($table.'.company_id', (int) $cid);
            }
        }

        if (array_key_exists('is_package_eligible', $filters)) {
            $b = $this->normalizeListingBoolean($filters['is_package_eligible']);
            if ($b !== null) {
                $query->where($query->getModel()->getTable().'.is_package_eligible', $b);
            }
        }

        if (array_key_exists('carry_on', $filters)) {
            $b = $this->normalizeListingBoolean($filters['carry_on']);
            if ($b !== null) {
                $query->where($table.'.hand_baggage_included', $b);
            }
        }

        if (array_key_exists('registration', $filters)) {
            $b = $this->normalizeListingBoolean($filters['registration']);
            if ($b !== null) {
                $query->where($table.'.online_checkin_allowed', $b);
            }
        }

        if (array_key_exists('reservation', $filters)) {
            $b = $this->normalizeListingBoolean($filters['reservation']);
            if ($b !== null) {
                $query->where($table.'.reservation_allowed', $b);
            }
        }

        if (array_key_exists('quantity', $filters)) {
            $qty = $filters['quantity'];
            if ($qty !== null && $qty !== '' && is_numeric($qty) && (int) $qty >= 0) {
                $query->where($table.'.seat_capacity_available', '>=', (int) $qty);
            }
        }

        if (array_key_exists('stops_count_max', $filters)) {
            $max = $filters['stops_count_max'];
            if ($max !== null && $max !== '' && is_numeric($max) && (int) $max >= 0) {
                $query->where($table.'.stops_count', '<=', (int) $max);
            }
        }

        if (array_key_exists('transit', $filters)) {
            $transit = $filters['transit'];
            if (is_string($transit) || is_numeric($transit)) {
                $value = strtolower(trim((string) $transit));
                if ($value !== '') {
                    if (in_array($value, ['direct', 'connected'], true)) {
                        $query->where($table.'.connection_type', $value);
                    } else {
                        $bool = $this->normalizeListingBoolean($value);
                        if ($bool === true) {
                            $query->where($table.'.stops_count', '>', 0);
                        } elseif ($bool === false) {
                            $query->where($table.'.stops_count', '=', 0);
                        }
                    }
                }
            }
        }

        if (array_key_exists('cancellation', $filters)) {
            $value = $filters['cancellation'];
            if (is_string($value) || is_numeric($value) || is_bool($value)) {
                $raw = strtolower(trim((string) $value));
                if ($raw !== '') {
                    if (in_array($raw, Flight::CANCELLATION_POLICY_TYPES, true)) {
                        $query->where($table.'.cancellation_policy_type', $raw);
                    } else {
                        $bool = $this->normalizeListingBoolean($value);
                        if ($bool === true) {
                            $query->where($table.'.cancellation_policy_type', '!=', 'non_refundable');
                        } elseif ($bool === false) {
                            $query->where($table.'.cancellation_policy_type', 'non_refundable');
                        }
                    }
                }
            }
        }

        if (array_key_exists('invoice_id', $filters)) {
            $invoiceId = $filters['invoice_id'];
            if ($invoiceId !== null && $invoiceId !== '' && is_numeric($invoiceId) && (int) $invoiceId > 0) {
                $query->whereHas('offer.bookingItems.booking.invoices', function (Builder $q) use ($invoiceId): void {
                    $q->where('id', (int) $invoiceId);
                });
            }
        }

        if (array_key_exists('user_email', $filters)) {
            $email = $filters['user_email'];
            if ($email !== null && $email !== '' && (is_string($email) || is_numeric($email))) {
                $needle = trim((string) $email);
                if ($needle !== '') {
                    $safeNeedle = '%'.addcslashes($needle, '%_\\').'%';
                    $query->whereHas('offer.bookingItems.booking.user', function (Builder $q) use ($safeNeedle): void {
                        $q->where('email', 'like', $safeNeedle);
                    });
                }
            }
        }

        if (array_key_exists('date', $filters)) {
            $v = $filters['date'];
            if ($v !== null && $v !== '') {
                try {
                    $query->whereDate($table.'.departure_at', Carbon::parse((string) $v)->toDateString());
                } catch (\Throwable) {
                    // ignore invalid date filter
                }
            }
        }
        if (array_key_exists('date_from', $filters)) {
            $v = $filters['date_from'];
            if ($v !== null && $v !== '') {
                try {
                    $query->where($table.'.departure_at', '>=', Carbon::parse((string) $v));
                } catch (\Throwable) {
                    // ignore invalid date filter
                }
            }
        }
        if (array_key_exists('date_to', $filters)) {
            $v = $filters['date_to'];
            if ($v !== null && $v !== '') {
                try {
                    $query->where($table.'.departure_at', '<=', Carbon::parse((string) $v));
                } catch (\Throwable) {
                    // ignore invalid date filter
                }
            }
        }

        if (array_key_exists('departure_at_from', $filters)) {
            $v = $filters['departure_at_from'];
            if ($v !== null && $v !== '') {
                try {
                    $query->where($table.'.departure_at', '>=', Carbon::parse($v));
                } catch (\Throwable) {
                    // ignore invalid date filter
                }
            }
        }
        if (array_key_exists('departure_at_to', $filters)) {
            $v = $filters['departure_at_to'];
            if ($v !== null && $v !== '') {
                try {
                    $query->where($table.'.departure_at', '<=', Carbon::parse($v));
                } catch (\Throwable) {
                }
            }
        }
        if (array_key_exists('arrival_at_from', $filters)) {
            $v = $filters['arrival_at_from'];
            if ($v !== null && $v !== '') {
                try {
                    $query->where($table.'.arrival_at', '>=', Carbon::parse($v));
                } catch (\Throwable) {
                }
            }
        }
        if (array_key_exists('arrival_at_to', $filters)) {
            $v = $filters['arrival_at_to'];
            if ($v !== null && $v !== '') {
                try {
                    $query->where($table.'.arrival_at', '<=', Carbon::parse($v));
                } catch (\Throwable) {
                }
            }
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

    private function nullableListingNumeric(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function applyAppearanceContextConstraints(Builder $query, mixed $context): void
    {
        if (! is_string($context) || trim($context) === '') {
            return;
        }

        $table = $query->getModel()->getTable();
        $normalized = strtolower(trim($context));

        if ($normalized === 'web') {
            $query->where($table.'.appears_in_web', true);

            return;
        }

        if ($normalized === 'admin') {
            $query->where($table.'.appears_in_admin', true);

            return;
        }

        if (in_array($normalized, ['zulu_admin', 'zulu-admin', 'platform_admin'], true)) {
            $query->where($table.'.appears_in_zulu_admin', true);
        }
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function flightStoreValidationRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'flight_code_internal' => ['required', 'string', 'max:191'],
            'service_type' => ['required', 'string', Rule::in(Flight::SERVICE_TYPES)],
            'departure_country' => ['required', 'string', 'max:191'],
            'departure_city' => ['required', 'string', 'max:191'],
            'departure_airport' => ['required', 'string', 'max:191'],
            'arrival_country' => ['required', 'string', 'max:191'],
            'arrival_city' => ['required', 'string', 'max:191'],
            'arrival_airport' => ['required', 'string', 'max:191'],
            'departure_airport_code' => ['nullable', 'string', 'max:8'],
            'arrival_airport_code' => ['nullable', 'string', 'max:8'],
            'departure_terminal' => ['nullable', 'string', 'max:32'],
            'arrival_terminal' => ['nullable', 'string', 'max:32'],
            'departure_at' => ['required', 'date'],
            'arrival_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:0'],
            'timezone_context' => ['nullable', 'string', 'max:64'],
            'check_in_close_at' => ['nullable', 'date'],
            'boarding_close_at' => ['nullable', 'date'],
            'connection_type' => ['required', 'string', Rule::in(Flight::CONNECTION_TYPES)],
            'stops_count' => ['required', 'integer', 'min:0', 'max:65535'],
            'connection_notes' => ['nullable', 'string'],
            'layover_summary' => ['nullable', 'string'],
            'cabin_class' => ['required', 'string', Rule::in(Flight::CABIN_CLASSES)],
            'seat_capacity_total' => ['required', 'integer', 'min:0'],
            'seat_capacity_available' => ['required', 'integer', 'min:0'],
            'fare_family' => ['nullable', 'string', 'max:191'],
            'seat_map_available' => ['required', 'boolean'],
            'seat_selection_policy' => ['nullable', 'string', 'max:191'],
            'adult_age_from' => ['required', 'integer', 'min:0', 'max:255'],
            'child_age_from' => ['required', 'integer', 'min:0', 'max:255'],
            'child_age_to' => ['required', 'integer', 'min:0', 'max:255'],
            'infant_age_from' => ['required', 'integer', 'min:0', 'max:255'],
            'infant_age_to' => ['required', 'integer', 'min:0', 'max:255'],
            'adult_price' => ['required', 'numeric', 'gt:0'],
            'child_price' => ['required', 'numeric', 'min:0'],
            'infant_price' => ['required', 'numeric', 'min:0'],
            'hand_baggage_included' => ['required', 'boolean'],
            'checked_baggage_included' => ['required', 'boolean'],
            'hand_baggage_weight' => ['nullable', 'string', 'max:32'],
            'checked_baggage_weight' => ['nullable', 'string', 'max:32'],
            'extra_baggage_allowed' => ['required', 'boolean'],
            'baggage_notes' => ['nullable', 'string'],
            'reservation_allowed' => ['required', 'boolean'],
            'online_checkin_allowed' => ['required', 'boolean'],
            'airport_checkin_allowed' => ['required', 'boolean'],
            'cancellation_policy_type' => ['required', 'string', Rule::in(Flight::CANCELLATION_POLICY_TYPES)],
            'change_policy_type' => ['required', 'string', Rule::in(Flight::CHANGE_POLICY_TYPES)],
            'reservation_deadline_at' => ['nullable', 'date'],
            'cancellation_deadline_at' => ['nullable', 'date'],
            'change_deadline_at' => ['nullable', 'date'],
            'policy_notes' => ['nullable', 'string'],
            'is_package_eligible' => ['required', 'boolean'],
            'appears_in_web' => ['required', 'boolean'],
            'appears_in_admin' => ['required', 'boolean'],
            'appears_in_zulu_admin' => ['required', 'boolean'],
            'status' => ['required', 'string', Rule::in(Flight::STATUSES)],
        ];
    }
}
