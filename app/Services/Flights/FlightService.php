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
use Illuminate\Validation\ValidationException;

class FlightService
{
    /**
     * Whitelisted query keys for operator flight listing ({@see applyFilters}).
     *
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
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
        'only_active_flights',
        'only_published_offers',
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
            'child_price' => 0,
            'infant_price' => 0,
        ];

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $data['company_id'] = $offer->company_id;

        $v = Validator::make($data, $this->createValidationRules());
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

        if ((int) $clean['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        $payload = Arr::only($clean, (new Flight)->getFillable());

        return DB::transaction(function () use ($payload, $offer) {
            $flight = Flight::query()->create($payload);
            $offer->update(['price' => $payload['adult_price']]);

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

        $createRules = $this->createValidationRules();
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

            if ($adultPriceSubmitted) {
                $flight->offer->update(['price' => $flight->adult_price]);
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
        $clean = Validator::make($data, $this->cabinPersistValidationRules())->validate();

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

        return FlightCabin::query()->create($payload)->fresh();
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

        $baseRules = $this->cabinPersistValidationRules();
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

        return $cabin->fresh();
    }

    public function deleteCabin(FlightCabin $cabin): void
    {
        $cabin->delete();
    }

    public function listCabins(Flight $flight): Collection
    {
        return $flight->cabins()->get();
    }

    /**
     * @return array<string, list<string|\Illuminate\Validation\Rules\In>>
     */
    private function cabinPersistValidationRules(): array
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
        $minPrice = $this->nullableListingNumeric($filters['min_price'] ?? null);
        $maxPrice = $this->nullableListingNumeric($filters['max_price'] ?? null);
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

        if (array_key_exists('company_id', $filters)) {
            $cid = $filters['company_id'];
            if ($cid !== null && $cid !== '' && is_numeric($cid)) {
                $query->where($query->getModel()->getTable().'.company_id', (int) $cid);
            }
        }

        if (array_key_exists('is_package_eligible', $filters)) {
            $b = $this->normalizeListingBoolean($filters['is_package_eligible']);
            if ($b !== null) {
                $query->where($query->getModel()->getTable().'.is_package_eligible', $b);
            }
        }

        if (array_key_exists('stops_count_max', $filters)) {
            $max = $filters['stops_count_max'];
            if ($max !== null && $max !== '' && is_numeric($max) && (int) $max >= 0) {
                $query->where($query->getModel()->getTable().'.stops_count', '<=', (int) $max);
            }
        }

        $table = $query->getModel()->getTable();

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

    /**
     * @return array<string, list<string|\Illuminate\Validation\Rules\In>>
     */
    private function createValidationRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
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
            'status' => ['required', 'string', Rule::in(Flight::STATUSES)],
        ];
    }
}
