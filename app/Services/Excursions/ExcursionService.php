<?php

namespace App\Services\Excursions;

use App\Http\Controllers\Api\ExcursionController;
use App\Models\Excursion;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExcursionService
{
    /**
     * Whitelisted query keys for operator / inventory excursion listing ({@see applyListingFilters}).
     *
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'company_id',
        'location',
        'country',
        'city',
        'general_category',
        'category',
        'excursion_type',
        'status',
        'language',
        'is_available',
        'is_bookable',
        // Step C2 — advanced inventory / operator listing.
        'date',
        'date_from',
        'date_to',
        'order_number',
        'invoice_id',
        'user_email',
        'price_min',
        'price_max',
        'min_price',
        'max_price',
        'price',
        // Step C3 — inventory / operator listing (rollout via platform settings).
        'appearance_context',
    ];

    /**
     * Validation rules for {@see ExcursionController::store} (expanded contract).
     *
     * @return array<string, mixed>
     */
    public function excursionStoreValidationRules(): array
    {
        return array_merge($this->excursionCoreWriteRules(), $this->excursionExpandedFieldRules(false));
    }

    /**
     * Validation rules for {@see ExcursionController::update} (partial; expanded optional).
     *
     * @return array<string, mixed>
     */
    public function excursionUpdateValidationRules(): array
    {
        return array_merge([
            'offer_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'location' => ['sometimes', 'string', 'max:255'],
            'duration' => ['sometimes', 'string', 'max:255'],
            'group_size' => ['sometimes', 'integer', 'min:1'],
        ], $this->excursionExpandedFieldRules(true));
    }

    /**
     * @return array<string, mixed>
     */
    private function excursionCoreWriteRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'location' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'string', 'max:255'],
            'group_size' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Expanded excursion fields (Phase B1 core mapping). When {@code $partial} is false (create),
     * optional keys use {@code sometimes} so minimal POST bodies remain valid.
     *
     * @return array<string, mixed>
     */
    private function excursionExpandedFieldRules(bool $partial): array
    {
        $opt = $partial ? ['sometimes', 'nullable'] : ['sometimes', 'nullable'];

        return [
            'country' => array_merge($opt, ['string', 'max:255']),
            'city' => array_merge($opt, ['string', 'max:255']),
            'general_category' => array_merge($opt, ['string', 'max:255']),
            'category' => array_merge($opt, ['string', 'max:255']),
            'excursion_type' => array_merge($opt, ['string', 'max:255']),
            'tour_name' => array_merge($opt, ['string', 'max:255']),
            'overview' => array_merge($opt, ['string']),
            'starts_at' => array_merge($opt, ['date']),
            'ends_at' => array_merge($opt, ['date']),
            'language' => array_merge($opt, ['string', 'max:255']),
            'ticket_max_count' => array_merge($opt, ['integer', 'min:1']),
            'status' => array_merge($opt, ['string', 'max:255']),
            'is_available' => array_merge($opt, ['boolean']),
            'is_bookable' => array_merge($opt, ['boolean']),
            'includes' => array_merge($opt, ['array', 'max:100']),
            'includes.*' => ['string', 'max:500'],
            'meeting_pickup' => array_merge($opt, ['string']),
            'additional_info' => array_merge($opt, ['string']),
            'cancellation_policy' => array_merge($opt, ['string']),
            'photos' => array_merge($opt, ['array', 'max:50']),
            'photos.*' => ['string', 'max:2048'],
            'price_by_dates' => array_merge($opt, ['array', 'max:366']),
            'price_by_dates.*.date' => ['required', 'date_format:Y-m-d'],
            'price_by_dates.*.price' => ['required', 'numeric', 'min:0'],
            'visibility_rule' => array_merge($opt, ['string', Rule::in(app(OfferVisibilityService::class)->getVisibilityRules())]),
            'appears_in_web' => array_merge($opt, ['boolean']),
            'appears_in_admin' => array_merge($opt, ['boolean']),
            'appears_in_zulu_admin' => array_merge($opt, ['boolean']),
        ];
    }

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
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Excursion>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        $query = $this->baseTenantExcursionQuery($companyIds);
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
        $query = $this->baseTenantExcursionQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultListOrdering($query);

        return $query->with(['offer'])->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int|string $id, array $companyIds): ?Excursion
    {
        if ($companyIds === []) {
            return null;
        }

        return $this->baseTenantExcursionQuery($companyIds)
            ->whereKey($id)
            ->with(['offer'])
            ->first();
    }

    public function findByIdWithExcursionOffer(int|string $id): ?Excursion
    {
        return Excursion::query()
            ->whereKey($id)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'excursion');
            })
            ->with(['offer'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Excursion
    {
        $offer = Offer::query()->findOrFail((int) ($data['offer_id'] ?? 0));

        if ($offer->type !== 'excursion') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type excursion.'],
            ]);
        }

        if (isset($data['company_id']) && (int) $data['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        if (Excursion::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['An excursion already exists for this offer.'],
            ]);
        }

        $fillable = (new Excursion)->getFillable();
        $payload = Arr::only($data, $fillable);

        $this->assertExcursionScheduleOrder(
            $payload['starts_at'] ?? null,
            $payload['ends_at'] ?? null
        );

        return DB::transaction(function () use ($payload, $offer): Excursion {
            $excursion = Excursion::query()->create($payload);
            $minPrice = $this->minUsablePriceFromPriceByDates($excursion->price_by_dates);
            if ($minPrice !== null) {
                $offer->update(['price' => $minPrice]);
            }

            return $excursion->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Excursion $excursion, array $data): Excursion
    {
        $priceByDatesProvided = array_key_exists('price_by_dates', $data);
        $fillable = (new Excursion)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id']);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        $mergedStarts = array_key_exists('starts_at', $data)
            ? $data['starts_at']
            : $excursion->starts_at?->toIso8601String();
        $mergedEnds = array_key_exists('ends_at', $data)
            ? $data['ends_at']
            : $excursion->ends_at?->toIso8601String();
        $this->assertExcursionScheduleOrder($mergedStarts, $mergedEnds);

        return DB::transaction(function () use ($excursion, $data, $priceByDatesProvided): Excursion {
            $excursion->fill($data);
            $excursion->save();

            if ($priceByDatesProvided) {
                $minPrice = $this->minUsablePriceFromPriceByDates($excursion->price_by_dates);
                if ($minPrice !== null) {
                    $excursion->offer->update(['price' => $minPrice]);
                }
            }

            return $excursion->refresh();
        });
    }

    public function delete(Excursion $excursion): void
    {
        DB::transaction(fn () => $excursion->delete());
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function baseTenantExcursionQuery(array $companyIds): Builder
    {
        $query = Excursion::query();
        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('offer', function (Builder $q) use ($companyIds): void {
            $q->where('type', 'excursion')
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

        // Offer price aliases (backward compatible with Transfer-style listing).
        if (array_key_exists('min_price', $filters)
            && ($filters['min_price'] !== null && $filters['min_price'] !== '')
            && (! array_key_exists('price_min', $filters) || $filters['price_min'] === null || $filters['price_min'] === '')
        ) {
            $filters['price_min'] = $filters['min_price'];
        }

        if (array_key_exists('max_price', $filters)
            && ($filters['max_price'] !== null && $filters['max_price'] !== '')
            && (! array_key_exists('price_max', $filters) || $filters['price_max'] === null || $filters['price_max'] === '')
        ) {
            $filters['price_max'] = $filters['max_price'];
        }

        if (array_key_exists('company_id', $filters) && $filters['company_id'] !== null && $filters['company_id'] !== '') {
            $companyId = (int) $filters['company_id'];
            $query->whereHas('offer', function (Builder $q) use ($companyId): void {
                $q->where('company_id', $companyId);
            });
        }

        $table = $query->getModel()->getTable();

        // Step C3: visibility_rule + appearance flags (rollout via platform settings).
        $excursionVisibilityControlsEnabled = app(PlatformSettingsService::class)->get(
            'excursion_visibility_controls_enabled',
            false
        ) === true;
        $appearanceContext = $filters['appearance_context'] ?? null;
        if ($excursionVisibilityControlsEnabled === true && is_string($appearanceContext) && trim($appearanceContext) !== '') {
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

        if (array_key_exists('location', $filters)) {
            $value = $filters['location'];
            if ($value !== null && $value !== '' && (is_string($value) || is_numeric($value))) {
                $table = $query->getModel()->getTable();
                $query->where($table.'.location', 'like', '%'.$this->likeEscape((string) $value).'%');
            }
        }

        foreach (['country', 'city', 'general_category', 'category', 'excursion_type', 'status', 'language'] as $column) {
            if (! array_key_exists($column, $filters)) {
                continue;
            }
            $value = $filters[$column];
            if ($value === null || $value === '' || (! is_string($value) && ! is_numeric($value))) {
                continue;
            }
            $table = $query->getModel()->getTable();
            $query->where($table.'.'.$column, 'like', '%'.$this->likeEscape((string) $value).'%');
        }

        foreach (['is_available', 'is_bookable'] as $boolColumn) {
            if (! array_key_exists($boolColumn, $filters)) {
                continue;
            }
            $raw = $filters[$boolColumn];
            if ($raw === null || $raw === '') {
                continue;
            }
            $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                continue;
            }
            $table = $query->getModel()->getTable();
            $query->where($table.'.'.$boolColumn, $bool);
        }

        $this->applyExcursionScheduleDateFilters($query, $table, $filters);
        $this->applyOfferPriceListingFilters($query, $filters);
        $this->applyBookingRelatedListingFilters($query, $filters);
    }

    /**
     * Excursion schedule: {@code starts_at} / {@code ends_at} overlap with a calendar day or a date range (day granularity).
     */
    private function applyExcursionScheduleDateFilters(Builder $query, string $table, array $filters): void
    {
        $dateOnly = array_key_exists('date', $filters)
            ? $this->parseFlexibleListingDate($filters['date'])
            : null;

        $dateFrom = $this->normalizeListingDate($filters['date_from'] ?? null);
        if ($dateFrom === null && array_key_exists('date_from', $filters)) {
            $dateFrom = $this->parseFlexibleListingDate($filters['date_from']);
        }

        $dateTo = $this->normalizeListingDate($filters['date_to'] ?? null);
        if ($dateTo === null && array_key_exists('date_to', $filters)) {
            $dateTo = $this->parseFlexibleListingDate($filters['date_to']);
        }

        if ($dateOnly !== null) {
            $d = $dateOnly;
            $query->where(function (Builder $q) use ($table, $d): void {
                $q->where(function (Builder $q2) use ($table, $d): void {
                    $q2->whereNotNull($table.'.starts_at')
                        ->whereNotNull($table.'.ends_at')
                        ->whereDate($table.'.starts_at', '<=', $d)
                        ->whereDate($table.'.ends_at', '>=', $d);
                })->orWhere(function (Builder $q2) use ($table, $d): void {
                    $q2->whereNotNull($table.'.starts_at')
                        ->whereNull($table.'.ends_at')
                        ->whereDate($table.'.starts_at', $d);
                })->orWhere(function (Builder $q2) use ($table, $d): void {
                    $q2->whereNull($table.'.starts_at')
                        ->whereNotNull($table.'.ends_at')
                        ->whereDate($table.'.ends_at', $d);
                });
            });
        }

        if ($dateFrom !== null || $dateTo !== null) {
            $fromBound = $dateFrom ?? '1970-01-01';
            $toBound = $dateTo ?? '2999-12-31';
            $query->where(function (Builder $q) use ($table, $fromBound, $toBound): void {
                $q->where(function (Builder $q2) use ($table, $fromBound, $toBound): void {
                    $q2->whereNotNull($table.'.starts_at')
                        ->whereNotNull($table.'.ends_at')
                        ->whereDate($table.'.starts_at', '<=', $toBound)
                        ->whereDate($table.'.ends_at', '>=', $fromBound);
                })->orWhere(function (Builder $q2) use ($table, $fromBound, $toBound): void {
                    $q2->whereNotNull($table.'.starts_at')
                        ->whereNull($table.'.ends_at')
                        ->whereDate($table.'.starts_at', '>=', $fromBound)
                        ->whereDate($table.'.starts_at', '<=', $toBound);
                })->orWhere(function (Builder $q2) use ($table, $fromBound, $toBound): void {
                    $q2->whereNull($table.'.starts_at')
                        ->whereNotNull($table.'.ends_at')
                        ->whereDate($table.'.ends_at', '>=', $fromBound)
                        ->whereDate($table.'.ends_at', '<=', $toBound);
                });
            });
        }
    }

    /**
     * Listing price bounds use {@code offers.price} (same as {@see ExcursionResource} {@code price}).
     */
    private function applyOfferPriceListingFilters(Builder $query, array $filters): void
    {
        $minPrice = $this->normalizeListingFloat($filters['price_min'] ?? null);
        $maxPrice = $this->normalizeListingFloat($filters['price_max'] ?? null);
        $priceExact = $this->normalizeListingFloat($filters['price'] ?? null);

        if ($minPrice === null && $maxPrice === null && $priceExact === null) {
            return;
        }

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

    /**
     * Linked bookings / invoices (same path as {@see TransferService} listing filters).
     */
    private function applyBookingRelatedListingFilters(Builder $query, array $filters): void
    {
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
                $q->where(function (Builder $q2) use ($like): void {
                    $q2->where('unique_booking_reference', 'like', $like);
                    if (Schema::hasColumn('invoices', 'vendor_locator')) {
                        $q2->orWhere('vendor_locator', 'like', $like);
                    }
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
     * Strict YYYY-MM-DD (Transfer-style).
     */
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

    /**
     * Flexible date for {@code date} query (invalid values ignored safely).
     */
    private function parseFlexibleListingDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Minimum numeric {@code price} across {@code price_by_dates} rows, or null when sync must not run.
     *
     * @param  mixed  $priceByDates  Excursion {@code price_by_dates} cast value
     */
    private function minUsablePriceFromPriceByDates(mixed $priceByDates): ?float
    {
        if (! is_array($priceByDates) || $priceByDates === []) {
            return null;
        }

        $candidates = [];
        foreach ($priceByDates as $row) {
            if (! is_array($row) || ! array_key_exists('price', $row)) {
                continue;
            }
            $p = $row['price'];
            if ($p === null || $p === '') {
                continue;
            }
            if (! is_numeric($p)) {
                continue;
            }
            $f = (float) $p;
            if (! is_finite($f) || $f < 0) {
                continue;
            }
            $candidates[] = $f;
        }

        if ($candidates === []) {
            return null;
        }

        return min($candidates);
    }

    /**
     * @param  mixed  $startsAt  ISO date string or null
     * @param  mixed  $endsAt  ISO date string or null
     */
    private function assertExcursionScheduleOrder(mixed $startsAt, mixed $endsAt): void
    {
        if ($startsAt === null || $startsAt === '' || $endsAt === null || $endsAt === '') {
            return;
        }

        $start = Carbon::parse((string) $startsAt);
        $end = Carbon::parse((string) $endsAt);
        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'ends_at' => ['The end time must be on or after the start time.'],
            ]);
        }
    }

    private function likeEscape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
