<?php

namespace App\Services\Hotels;

use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\HotelRoomPricing;
use App\Models\Offer;
use App\Services\Infrastructure\PlatformSettingsService;
use App\Services\Offers\OfferVisibilityService;
use Carbon\Carbon;
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
        'route',
        'country',
        'room_type',
        'is_package_eligible',
        'free_cancellation',
        'invoice_id',
        'date',
        'date_from',
        'date_to',
        'user_email',
        'min_price',
        'max_price',
        'price_min',
        'price_max',
        'starting_price_min',
        'starting_price_max',
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

        foreach ($this->hotelCreateDefaults() as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $hotelRules = $this->hotelStoreValidationRules();
        $v = Validator::make($data, $hotelRules);
        $hotelAttrs = $v->validate();
        $hotelAttrs['company_id'] = $offer->company_id;

        if ($roomsPayload !== []) {
            $this->validateRoomsPayload($roomsPayload);
        }

        return DB::transaction(function () use ($hotelAttrs, $roomsPayload) {
            $hotel = Hotel::query()->create(
                Arr::only($hotelAttrs, (new Hotel)->getFillable())
            );

            $this->persistRoomsForHotel($hotel, $roomsPayload);
            $this->syncHotelOfferPriceFromActiveRoomPricings($hotel);

            return $hotel->fresh(['rooms.pricings']);
        });
    }

    /**
     * Merge rooms (and nested pricings) for a hotel: upsert by optional id, delete omitted rows.
     *
     * @param  array<int, array<string, mixed>>  $roomsPayload
     */
    protected function syncRooms(Hotel $hotel, array $roomsPayload): void
    {
        $this->validateRoomsPayload($roomsPayload, $hotel);

        $roomIdsToKeep = [];
        foreach ($roomsPayload as $roomData) {
            if (! is_array($roomData)) {
                continue;
            }
            if (! array_key_exists('id', $roomData) || $roomData['id'] === null || $roomData['id'] === '') {
                continue;
            }
            $roomIdsToKeep[] = (int) $roomData['id'];
        }
        $roomIdsToKeep = array_values(array_unique($roomIdsToKeep));

        if ($roomIdsToKeep !== []) {
            $hotel->rooms()->whereNotIn('id', $roomIdsToKeep)->delete();
        } else {
            $hotel->rooms()->delete();
        }

        $roomFillable = (new HotelRoom)->getFillable();

        foreach ($roomsPayload as $roomData) {
            if (! is_array($roomData)) {
                continue;
            }

            $pricings = Arr::pull($roomData, 'pricings', []);
            if (! is_array($pricings)) {
                $pricings = [];
            }

            $existingRoomId = null;
            if (array_key_exists('id', $roomData) && $roomData['id'] !== null && $roomData['id'] !== '') {
                $existingRoomId = (int) $roomData['id'];
            }
            unset($roomData['id']);

            foreach ($this->hotelRoomCreateDefaults() as $key => $value) {
                if (! array_key_exists($key, $roomData)) {
                    $roomData[$key] = $value;
                }
            }

            $roomAttrs = Arr::only($roomData, $roomFillable);
            unset($roomAttrs['hotel_id']);

            if ($existingRoomId !== null) {
                $room = HotelRoom::query()
                    ->where('hotel_id', $hotel->getKey())
                    ->whereKey($existingRoomId)
                    ->firstOrFail();
                $room->fill($roomAttrs);
                $room->save();
            } else {
                $createAttrs = Arr::only($roomData, $roomFillable);
                unset($createAttrs['hotel_id']);
                $room = $hotel->rooms()->create($createAttrs);
            }

            $this->upsertPricingsForRoom($room, $pricings);
        }
    }

    /**
     * Upsert pricing rows for a room: optional id updates in place; omitted ids are deleted.
     *
     * @param  array<int, array<string, mixed>>  $pricingsPayload
     */
    protected function upsertPricingsForRoom(HotelRoom $room, array $pricingsPayload): void
    {
        $pricingFillable = (new HotelRoomPricing)->getFillable();

        $pricingIdsToKeep = [];
        foreach ($pricingsPayload as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! array_key_exists('id', $row) || $row['id'] === null || $row['id'] === '') {
                continue;
            }
            $pricingIdsToKeep[] = (int) $row['id'];
        }
        $pricingIdsToKeep = array_values(array_unique($pricingIdsToKeep));

        if ($pricingIdsToKeep !== []) {
            $room->pricings()->whereNotIn('id', $pricingIdsToKeep)->delete();
        } else {
            $room->pricings()->delete();
        }

        foreach ($pricingsPayload as $pricingData) {
            if (! is_array($pricingData)) {
                continue;
            }

            $existingPricingId = null;
            if (array_key_exists('id', $pricingData) && $pricingData['id'] !== null && $pricingData['id'] !== '') {
                $existingPricingId = (int) $pricingData['id'];
            }
            unset($pricingData['id']);

            foreach ($this->hotelRoomPricingDefaults() as $key => $value) {
                if (! array_key_exists($key, $pricingData)) {
                    $pricingData[$key] = $value;
                }
            }

            Validator::make($pricingData, $this->pricingRowRules())->validate();

            $attrs = Arr::only($pricingData, $pricingFillable);
            unset($attrs['hotel_room_id']);

            if ($existingPricingId !== null) {
                $pricing = HotelRoomPricing::query()
                    ->where('hotel_room_id', $room->getKey())
                    ->whereKey($existingPricingId)
                    ->firstOrFail();
                $pricing->fill($attrs);
                $pricing->save();
            } else {
                $room->pricings()->create(
                    Arr::only($pricingData, $pricingFillable)
                );
            }
        }
    }

    /**
     * Insert rooms and pricings for a hotel (used by create and syncRooms).
     *
     * @param  array<int, array<string, mixed>>  $roomsPayload
     */
    protected function persistRoomsForHotel(Hotel $hotel, array $roomsPayload): void
    {
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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Hotel $hotel, array $data): Hotel
    {
        $roomsRequested = array_key_exists('rooms', $data);
        $roomsPayload = $roomsRequested ? $data['rooms'] : null;
        if ($roomsRequested) {
            unset($data['rooms']);
        }

        $fillable = (new Hotel)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id'], $data['company_id']);

        if ($data === [] && ! $roomsRequested) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        if ($roomsRequested && ! is_array($roomsPayload)) {
            throw ValidationException::withMessages([
                'rooms' => ['Invalid rooms payload.'],
            ]);
        }

        return DB::transaction(function () use ($hotel, $data, $roomsRequested, $roomsPayload) {
            if ($data !== []) {
                $createRules = $this->hotelStoreValidationRules();
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
            }

            if ($roomsRequested && is_array($roomsPayload)) {
                $this->syncRooms($hotel, $roomsPayload);
                $this->syncHotelOfferPriceFromActiveRoomPricings($hotel);
            }

            return $hotel->refresh();
        });
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

        $hotels = $query->get();
        $hotels->load(['offer', 'rooms.pricings']);

        return $hotels;
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

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->load(['offer', 'rooms.pricings']);

        return $paginator;
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

        // Mapping-aligned aliases (backward compatible)
        if (array_key_exists('route', $filters)
            && ($filters['route'] !== null && $filters['route'] !== '')
            && (! array_key_exists('city', $filters) || $filters['city'] === null || $filters['city'] === '')
        ) {
            $filters['city'] = $filters['route'];
        }

        if (array_key_exists('min_price', $filters)
            && ($filters['min_price'] !== null && $filters['min_price'] !== '')
            && (! array_key_exists('starting_price_min', $filters) || $filters['starting_price_min'] === null || $filters['starting_price_min'] === '')
        ) {
            $filters['starting_price_min'] = $filters['min_price'];
        }

        if (array_key_exists('max_price', $filters)
            && ($filters['max_price'] !== null && $filters['max_price'] !== '')
            && (! array_key_exists('starting_price_max', $filters) || $filters['starting_price_max'] === null || $filters['starting_price_max'] === '')
        ) {
            $filters['starting_price_max'] = $filters['max_price'];
        }

        if (array_key_exists('price_min', $filters)
            && ($filters['price_min'] !== null && $filters['price_min'] !== '')
            && (! array_key_exists('starting_price_min', $filters) || $filters['starting_price_min'] === null || $filters['starting_price_min'] === '')
        ) {
            $filters['starting_price_min'] = $filters['price_min'];
        }

        if (array_key_exists('price_max', $filters)
            && ($filters['price_max'] !== null && $filters['price_max'] !== '')
            && (! array_key_exists('starting_price_max', $filters) || $filters['starting_price_max'] === null || $filters['starting_price_max'] === '')
        ) {
            $filters['starting_price_max'] = $filters['price_max'];
        }

        if (array_key_exists('company_id', $filters) && $filters['company_id'] !== null && $filters['company_id'] !== '') {
            $query->where($table.'.company_id', (int) $filters['company_id']);
        }

        // Step C3: apply hotel visibility_rule filtering in admin inventory pages.
        // Discovery/public web is handled separately in DiscoveryService.
        $hotelVisibilityControlsEnabled = app(PlatformSettingsService::class)->get(
            'hotel_visibility_controls_enabled',
            false
        ) === true;
        $appearanceContext = $filters['appearance_context'] ?? null;
        if ($hotelVisibilityControlsEnabled === true && is_string($appearanceContext) && trim($appearanceContext) !== '') {
            $ctx = strtolower(trim($appearanceContext));
            // zulu-admin inventory context behaves like "admin".
            $mappedContext = $ctx === 'web' ? 'web' : 'admin';
            app(OfferVisibilityService::class)->applyVisibilityFilter($query, $mappedContext);
        }

        foreach (['status', 'availability_status', 'city', 'country'] as $key) {
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

        if (array_key_exists('free_cancellation', $filters)) {
            $b = $this->normalizeListingBoolean($filters['free_cancellation']);
            if ($b !== null) {
                $query->where($table.'.free_cancellation', $b);
            }
        }

        if (array_key_exists('room_type', $filters)) {
            $roomType = $filters['room_type'];
            if ($roomType !== null && $roomType !== '' && (is_string($roomType) || is_numeric($roomType))) {
                $needle = trim((string) $roomType);
                if ($needle !== '') {
                    $safeNeedle = '%'.addcslashes($needle, '%_\\').'%';
                    $query->whereHas('rooms', function (Builder $q) use ($safeNeedle): void {
                        $q->where('room_type', 'like', $safeNeedle);
                    });
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

        // Date filtering is invoice-based (hotel bookings have no standalone date field).
        $hasCheckIn = Schema::hasColumn('invoices', 'check_in');
        $hasCheckOut = Schema::hasColumn('invoices', 'check_out');

        $parseDate = function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }

            try {
                // Always return YYYY-MM-DD string.
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

        $min = array_key_exists('starting_price_min', $filters)
            ? $this->normalizeListingFloat($filters['starting_price_min'])
            : null;
        $max = array_key_exists('starting_price_max', $filters)
            ? $this->normalizeListingFloat($filters['starting_price_max'])
            : null;

        // List/filter anchor: parent offer.price (synced from MIN(active room pricing); see syncHotelOfferPriceFromActiveRoomPricings).
        if ($min !== null || $max !== null) {
            $query->whereHas('offer', function (Builder $q) use ($min, $max): void {
                if ($min !== null) {
                    $q->where('price', '>=', $min);
                }
                if ($max !== null) {
                    $q->where('price', '<=', $max);
                }
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
     * Minimum hotel_room_pricings.price among rows with status "active" for this hotel.
     * Drives {@see syncHotelOfferPriceFromActiveRoomPricings}; listing filters use {@code offers.price}.
     */
    private function minimumActiveHotelRoomPrice(Hotel $hotel): ?float
    {
        $raw = HotelRoomPricing::query()
            ->where('status', 'active')
            ->whereHas('room', function (Builder $q) use ($hotel): void {
                $q->where('hotel_id', $hotel->getKey());
            })
            ->min('price');

        if ($raw === null) {
            return null;
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * Sets parent offer price to the derived minimum when at least one active room pricing exists.
     */
    private function syncHotelOfferPriceFromActiveRoomPricings(Hotel $hotel): void
    {
        $min = $this->minimumActiveHotelRoomPrice($hotel);
        if ($min === null) {
            return;
        }

        $hotel->loadMissing('offer');
        if ($hotel->offer === null) {
            return;
        }

        $hotel->offer->update(['price' => $min]);
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
            // Step C3: controls where hotel is visible + whether it can be included in packages.
            'visibility_rule' => 'show_all',
            'appears_in_packages' => true,
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
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function hotelStoreValidationRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'hotel_name' => ['required', 'string', 'max:255'],
            'property_type' => ['required', 'string', 'max:64'],
            'hotel_type' => ['required', 'string', 'max:64'],
            'star_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'country' => ['required', 'string', 'max:120'],
            'region_or_state' => ['nullable', 'string', 'max:255'],
            'visibility_rule' => ['nullable', 'string', Rule::in(['show_all', 'show_accepted_only', 'hide_rejected'])],
            'appears_in_packages' => ['boolean'],
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
     * @return array<string, array<int, string|ValidationRule>>
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
    protected function validateRoomsPayload(array $roomsPayload, ?Hotel $hotel = null): void
    {
        $roomIdRules = ['prohibited'];
        $pricingIdRules = ['prohibited'];
        if ($hotel !== null) {
            $roomIdRules = [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('hotel_rooms', 'id')->where('hotel_id', $hotel->getKey()),
            ];
            $pricingIdRules = ['sometimes', 'nullable', 'integer'];
        }

        $v = Validator::make(
            ['rooms' => $roomsPayload],
            [
                'rooms' => ['required', 'array', 'min:1'],
                'rooms.*.id' => $roomIdRules,
                'rooms.*.room_type' => ['required', 'string', 'max:255'],
                'rooms.*.room_name' => ['required', 'string', 'max:255'],
                'rooms.*.max_adults' => ['required', 'integer', 'min:1'],
                'rooms.*.max_children' => ['sometimes', 'integer', 'min:0'],
                'rooms.*.max_total_guests' => ['required', 'integer', 'min:1'],
                'rooms.*.pricings' => ['nullable', 'array'],
                'rooms.*.pricings.*.id' => $pricingIdRules,
                'rooms.*.pricings.*.price' => ['required', 'numeric', 'gt:0'],
                'rooms.*.pricings.*.currency' => ['required', 'string', 'size:3'],
                'rooms.*.pricings.*.pricing_mode' => ['sometimes', 'string', 'max:32'],
                'rooms.*.pricings.*.valid_from' => ['nullable', 'date'],
                'rooms.*.pricings.*.valid_to' => ['nullable', 'date'],
                'rooms.*.pricings.*.min_nights' => ['nullable', 'integer', 'min:1'],
                'rooms.*.pricings.*.status' => ['sometimes', 'string', 'max:32'],
            ]
        );

        $v->after(function (\Illuminate\Validation\Validator $validator) use ($roomsPayload, $hotel): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $seenRoomIds = [];
            foreach ($roomsPayload as $idx => $room) {
                if (! is_array($room)) {
                    continue;
                }

                if ($hotel === null) {
                    foreach (['id'] as $forbidden) {
                        if (! array_key_exists($forbidden, $room)) {
                            continue;
                        }
                        $raw = $room[$forbidden];
                        if ($raw !== null && $raw !== '') {
                            $validator->errors()->add(
                                "rooms.$idx.$forbidden",
                                'Room id cannot be set when creating a hotel.'
                            );
                        }
                    }
                } else {
                    $rid = $room['id'] ?? null;
                    if ($rid !== null && $rid !== '') {
                        $rid = (int) $rid;
                        if (isset($seenRoomIds[$rid])) {
                            $validator->errors()->add(
                                "rooms.$idx.id",
                                'Duplicate room id in payload.'
                            );
                        }
                        $seenRoomIds[$rid] = true;
                    }
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

                    continue;
                }

                $roomIdForPricing = $room['id'] ?? null;
                $roomIdForPricing = ($roomIdForPricing !== null && $roomIdForPricing !== '')
                    ? (int) $roomIdForPricing
                    : null;

                foreach ($pricings as $pidx => $prow) {
                    if (! is_array($prow)) {
                        continue;
                    }

                    if ($hotel === null) {
                        if (array_key_exists('id', $prow) && $prow['id'] !== null && $prow['id'] !== '') {
                            $validator->errors()->add(
                                "rooms.$idx.pricings.$pidx.id",
                                'Pricing id cannot be set when creating a hotel.'
                            );
                        }

                        continue;
                    }

                    $pid = $prow['id'] ?? null;
                    if ($pid === null || $pid === '') {
                        continue;
                    }

                    if ($roomIdForPricing === null) {
                        $validator->errors()->add(
                            "rooms.$idx.pricings.$pidx.id",
                            'Pricing id is not allowed for a new room row.'
                        );

                        continue;
                    }

                    $belongs = HotelRoomPricing::query()
                        ->whereKey((int) $pid)
                        ->where('hotel_room_id', $roomIdForPricing)
                        ->exists();
                    if (! $belongs) {
                        $validator->errors()->add(
                            "rooms.$idx.pricings.$pidx.id",
                            'The selected pricing id is invalid for this room.'
                        );
                    }
                }
            }
        });

        $v->validate();
    }
}
