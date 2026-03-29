<?php

namespace App\Services\Hotels;

use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\HotelRoomPricing;
use App\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HotelService
{
    /**
     * Whitelisted query keys for operator hotel listing ({@see applyListingFilters}).
     *
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'company_id',
        'status',
        'availability_status',
        'city',
        'is_package_eligible',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Hotel
    {
        $roomsPayload = Arr::pull($data, 'rooms', []);
        if (! is_array($roomsPayload)) {
            $roomsPayload = [];
        }

        $offer = Offer::query()->findOrFail($data['offer_id'] ?? 0);

        if ($offer->type !== 'hotel') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type hotel.'],
            ]);
        }

        if (Hotel::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['A hotel already exists for this offer.'],
            ]);
        }

        $data['company_id'] = $offer->company_id;

        foreach ($this->hotelCreateDefaults() as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $hotelRules = $this->hotelCreateValidationRules();
        $v = Validator::make($data, $hotelRules);
        $hotelAttrs = $v->validate();

        if ((int) $hotelAttrs['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        if ($roomsPayload !== []) {
            $this->validateRoomsPayload($roomsPayload);
        }

        return DB::transaction(function () use ($hotelAttrs, $roomsPayload) {
            $hotel = Hotel::query()->create(
                Arr::only($hotelAttrs, (new Hotel)->getFillable())
            );

            foreach ($roomsPayload as $roomData) {
                if (! is_array($roomData)) {
                    continue;
                }
                $pricings = Arr::pull($roomData, 'pricings', []);
                if (! is_array($pricings)) {
                    $pricings = [];
                }
                foreach ($this->hotelRoomCreateDefaults() as $key => $value) {
                    if (! array_key_exists($key, $roomData)) {
                        $roomData[$key] = $value;
                    }
                }
                $room = $hotel->rooms()->create(
                    Arr::only($roomData, (new HotelRoom)->getFillable())
                );

                foreach ($pricings as $pricingData) {
                    if (! is_array($pricingData)) {
                        continue;
                    }
                    foreach ($this->hotelRoomPricingDefaults() as $key => $value) {
                        if (! array_key_exists($key, $pricingData)) {
                            $pricingData[$key] = $value;
                        }
                    }
                    Validator::make($pricingData, $this->pricingRowRules())->validate();
                    $room->pricings()->create(
                        Arr::only($pricingData, (new HotelRoomPricing)->getFillable())
                    );
                }
            }

            return $hotel->fresh(['rooms.pricings']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Hotel $hotel, array $data): Hotel
    {
        $fillable = (new Hotel)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id'], $data['company_id']);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        $createRules = $this->hotelCreateValidationRules();
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

        $clean = Validator::make($data, $partialRules)->validate();

        $hotel->fill(Arr::only($clean, array_keys($data)));
        $hotel->save();

        return $hotel->refresh();
    }

    public function delete(Hotel $hotel): void
    {
        DB::transaction(function () use ($hotel): void {
            $hotel->delete();
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

        return $filters;
    }

    /**
     * Tenant-scoped hotels (offer type hotel only), with optional filters and stable ordering.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Hotel>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        $query = $this->baseTenantHotelQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultHotelListOrdering($query);

        return $query->with(['offer', 'rooms.pricings'])->get();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateForCompanies(array $companyIds, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->baseTenantHotelQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultHotelListOrdering($query);

        return $query->with(['offer', 'rooms.pricings'])->paginate($perPage);
    }

    /**
     * Single hotel in tenant scope with parent offer type hotel.
     *
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int|string $id, array $companyIds): ?Hotel
    {
        if ($companyIds === []) {
            return null;
        }

        return $this->baseTenantHotelQuery($companyIds)
            ->whereKey($id)
            ->with(['offer', 'rooms.pricings'])
            ->first();
    }

    /**
     * Resolve a hotel row by id when the parent offer is type hotel (no tenant filter).
     * Used only for write-access edge cases (403 vs 404); do not expose without checks.
     */
    public function findByIdWithHotelOffer(int|string $id): ?Hotel
    {
        return Hotel::query()
            ->whereKey($id)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'hotel');
            })
            ->first();
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function baseTenantHotelQuery(array $companyIds): Builder
    {
        $query = Hotel::query();
        $table = $query->getModel()->getTable();
        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->whereIn($table.'.company_id', $companyIds)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'hotel');
            });
    }

    private function applyDefaultHotelListOrdering(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $query->orderBy($table.'.hotel_name')->orderBy($table.'.id');
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

        foreach (['status', 'availability_status', 'city'] as $key) {
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
     * @return array<string, mixed>
     */
    protected function hotelCreateDefaults(): array
    {
        return [
            'star_rating' => null,
            'region_or_state' => null,
            'district_or_area' => null,
            'full_address' => null,
            'latitude' => null,
            'longitude' => null,
            'short_description' => null,
            'main_image' => null,
            'check_in_time' => null,
            'check_out_time' => null,
            'cancellation_policy_type' => null,
            'cancellation_deadline_at' => null,
            'no_show_policy' => null,
            'review_score' => null,
            'review_label' => null,
            'room_inventory_mode' => null,
            'free_wifi' => false,
            'parking' => false,
            'airport_shuttle' => false,
            'indoor_pool' => false,
            'outdoor_pool' => false,
            'room_service' => false,
            'front_desk_24h' => false,
            'child_friendly' => false,
            'accessibility_support' => false,
            'pets_allowed' => false,
            'free_cancellation' => false,
            'prepayment_required' => false,
            'review_count' => 0,
            'availability_status' => 'available',
            'bookable' => true,
            'is_package_eligible' => false,
            'status' => 'draft',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function hotelRoomCreateDefaults(): array
    {
        return [
            'max_children' => 0,
            'bed_type' => null,
            'bed_count' => 1,
            'room_size' => null,
            'room_view' => null,
            'view_type' => null,
            'room_images' => null,
            'room_inventory_count' => null,
            'private_bathroom' => false,
            'smoking_allowed' => false,
            'air_conditioning' => false,
            'wifi' => false,
            'tv' => false,
            'mini_fridge' => false,
            'tea_coffee_maker' => false,
            'kettle' => false,
            'washing_machine' => false,
            'soundproofing' => false,
            'terrace_or_balcony' => false,
            'patio' => false,
            'bath' => false,
            'shower' => false,
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function hotelRoomPricingDefaults(): array
    {
        return [
            'pricing_mode' => 'per_night',
            'valid_from' => null,
            'valid_to' => null,
            'min_nights' => null,
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, array<int, string|\Illuminate\Contracts\Validation\ValidationRule>>
     */
    protected function hotelCreateValidationRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'hotel_name' => ['required', 'string', 'max:255'],
            'property_type' => ['required', 'string', 'max:64'],
            'hotel_type' => ['required', 'string', 'max:64'],
            'star_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'country' => ['required', 'string', 'max:120'],
            'region_or_state' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'district_or_area' => ['nullable', 'string', 'max:255'],
            'full_address' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'short_description' => ['nullable', 'string'],
            'main_image' => ['nullable', 'string', 'max:2048'],
            'check_in_time' => ['nullable', 'string', 'max:16'],
            'check_out_time' => ['nullable', 'string', 'max:16'],
            'meal_type' => ['required', 'string', 'max:64'],
            'free_wifi' => ['boolean'],
            'parking' => ['boolean'],
            'airport_shuttle' => ['boolean'],
            'indoor_pool' => ['boolean'],
            'outdoor_pool' => ['boolean'],
            'room_service' => ['boolean'],
            'front_desk_24h' => ['boolean'],
            'child_friendly' => ['boolean'],
            'accessibility_support' => ['boolean'],
            'pets_allowed' => ['boolean'],
            'free_cancellation' => ['boolean'],
            'cancellation_policy_type' => ['nullable', 'string', 'max:64'],
            'cancellation_deadline_at' => ['nullable', 'date'],
            'prepayment_required' => ['boolean'],
            'no_show_policy' => ['nullable', 'string', 'max:255'],
            'review_score' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'review_count' => ['integer', 'min:0'],
            'review_label' => ['nullable', 'string', 'max:255'],
            'availability_status' => ['required', 'string', 'max:32'],
            'bookable' => ['boolean'],
            'room_inventory_mode' => ['nullable', 'string', 'max:64'],
            'is_package_eligible' => ['boolean'],
            'status' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, array<int, string|\Illuminate\Contracts\Validation\ValidationRule>>
     */
    protected function pricingRowRules(): array
    {
        return [
            'price' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'pricing_mode' => ['required', 'string', 'max:32'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'min_nights' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @param  array<int, mixed>  $roomsPayload
     */
    protected function validateRoomsPayload(array $roomsPayload): void
    {
        $v = Validator::make(
            ['rooms' => $roomsPayload],
            [
                'rooms' => ['required', 'array', 'min:1'],
                'rooms.*.room_type' => ['required', 'string', 'max:255'],
                'rooms.*.room_name' => ['required', 'string', 'max:255'],
                'rooms.*.max_adults' => ['required', 'integer', 'min:1'],
                'rooms.*.max_children' => ['sometimes', 'integer', 'min:0'],
                'rooms.*.max_total_guests' => ['required', 'integer', 'min:1'],
                'rooms.*.pricings' => ['nullable', 'array'],
                'rooms.*.pricings.*.price' => ['required', 'numeric', 'gt:0'],
                'rooms.*.pricings.*.currency' => ['required', 'string', 'size:3'],
                'rooms.*.pricings.*.pricing_mode' => ['sometimes', 'string', 'max:32'],
                'rooms.*.pricings.*.valid_from' => ['nullable', 'date'],
                'rooms.*.pricings.*.valid_to' => ['nullable', 'date'],
                'rooms.*.pricings.*.min_nights' => ['nullable', 'integer', 'min:1'],
                'rooms.*.pricings.*.status' => ['sometimes', 'string', 'max:32'],
            ]
        );

        $v->after(function (\Illuminate\Validation\Validator $validator) use ($roomsPayload): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($roomsPayload as $idx => $room) {
                if (! is_array($room)) {
                    continue;
                }
                $adults = (int) ($room['max_adults'] ?? 0);
                $total = (int) ($room['max_total_guests'] ?? 0);
                if ($total < $adults) {
                    $validator->errors()->add(
                        "rooms.$idx.max_total_guests",
                        'Max total guests must be greater than or equal to max adults.'
                    );
                }
                $pricings = $room['pricings'] ?? [];
                if (! is_array($pricings) || $pricings === []) {
                    $validator->errors()->add(
                        "rooms.$idx.pricings",
                        'At least one pricing row is required when rooms are provided.'
                    );
                }
            }
        });

        $v->validate();
    }
}
