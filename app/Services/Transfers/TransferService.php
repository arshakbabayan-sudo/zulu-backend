<?php

namespace App\Services\Transfers;

use App\Models\Offer;
use App\Models\Transfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        'status',
        'availability_status',
        'transfer_type',
        'vehicle_category',
        'is_package_eligible',
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

        $data['company_id'] = $offer->company_id;
        $data = $this->applyTransferPayloadDefaults($data, $offer);

        $rules = $this->transferCreateValidationRules();
        $attrs = $this->runTransferValidator($data, $rules)->validate();

        if ((int) $attrs['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        return DB::transaction(function () use ($attrs) {
            return Transfer::query()->create(
                Arr::only($attrs, (new Transfer)->getFillable())
            );
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

        $createRules = $this->transferCreateValidationRules();
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

        return DB::transaction(function () use ($transfer, $clean, $data): Transfer {
            $transfer->fill(Arr::only($clean, array_keys($data)));
            $transfer->save();

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
            'status' => 'draft',
            'cancellation_policy_type' => 'non_refundable',
            'pricing_mode' => 'per_vehicle',
            'base_price' => $offerBaseline,
            'service_date' => now()->toDateString(),
            'pickup_time' => '09:00:00',
        ];
    }

    /**
     * @param  array<string, array<int, string|\Illuminate\Contracts\Validation\ValidationRule>>  $rules
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
     * @return array<string, array<int, string|\Illuminate\Contracts\Validation\ValidationRule>>
     */
    protected function transferCreateValidationRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'transfer_title' => ['required', 'string', 'max:255'],
            'transfer_type' => ['required', 'string', Rule::in(Transfer::TRANSFER_TYPES)],
            'pickup_country' => ['required', 'string', 'max:120'],
            'pickup_city' => ['required', 'string', 'max:255'],
            'pickup_point_type' => ['required', 'string', Rule::in(Transfer::POINT_TYPES)],
            'pickup_point_name' => ['required', 'string'],
            'dropoff_country' => ['required', 'string', 'max:120'],
            'dropoff_city' => ['required', 'string', 'max:255'],
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
}
