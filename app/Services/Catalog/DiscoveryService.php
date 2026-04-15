<?php

namespace App\Services\Catalog;

use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Offer;
use App\Models\ServiceConnection;
use App\Services\Infrastructure\PlatformSettingsService;
use App\Services\Offers\OfferNormalizationService;
use App\Services\Offers\OfferVisibilityService;
use App\Services\Pricing\PriceCalculatorService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class DiscoveryService
{
    public function __construct(
        private readonly OfferNormalizationService $normalizationService,
        private readonly OfferVisibilityService $offerVisibilityService,
        private readonly PlatformSettingsService $platformSettingsService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  string|null  $languageCode  Resolved locale for normalized titles (see {@see OfferNormalizationService}).
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function search(array $input, ?string $languageCode = null): array
    {
        $lang = $languageCode ?? config('app.locale', 'en');
        $perPage = (int) ($input['per_page'] ?? 20);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, (int) ($input['page'] ?? 1));
        $sort = $input['sort'] ?? 'price_asc';
        if (! in_array($sort, ['price_asc', 'price_desc', 'newest'], true)) {
            $sort = 'price_asc';
        }

        $moduleType = isset($input['module_type']) && $input['module_type'] !== ''
            ? (string) $input['module_type']
            : null;

        $query = Offer::query()->where('status', Offer::STATUS_PUBLISHED);

        if ($moduleType !== null) {
            $query->where('type', $moduleType);
        }

        $this->applyOfferPriceCurrencyFilters($query, $input);
        $this->applyLocationAndModuleFilters($query, $input, $moduleType);
        $this->applyDateRangeFilters($query, $input, $moduleType);
        $this->applyFreeCancellationFilter($query, $input, $moduleType);
        $this->applyPackageEligibleFilter($query, $input, $moduleType);
        $this->applyHistorySearchFilters($query, $input, $moduleType);

        match ($sort) {
            'price_desc' => $query->orderBy('price', 'desc'),
            'newest' => $query->orderBy('id', 'desc'),
            default => $query->orderBy('price', 'asc'),
        };

        /** @var LengthAwarePaginator<int, Offer> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $offer) {
            $relation = $this->relationNameForType($offer->type);
            if ($relation !== null) {
                $offer->loadMissing($relation === 'flight' ? 'flight.cabins' : $relation);
            }
            $normalized = $this->normalizationService->normalize($offer, true, $lang);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function searchPackageEligible(string $moduleType, array $overrides = []): array
    {
        $input = array_merge($overrides, [
            'module_type' => $moduleType,
            'is_package_eligible' => true,
        ]);

        return $this->search($input);
    }

    /**
     * @param  string|null  $languageCode  Resolved locale for offer title + normalized copy.
     * @return array{offer: array<string, mixed>, normalized: array<string, mixed>, hotel_rooms?: list<array<string, mixed>>, review_target?: array{entity_type: string, entity_id: int}}|null
     */
    public function findPublishedOfferWithNormalized(int $id, ?string $languageCode = null): ?array
    {
        $lang = $languageCode ?? config('app.locale', 'en');
        $offer = Offer::query()
            ->where('status', Offer::STATUS_PUBLISHED)
            ->whereKey($id)
            ->first();

        if ($offer === null) {
            return null;
        }

        $relation = $this->relationNameForType($offer->type);
        if ($relation !== null) {
            match ($relation) {
                'flight' => $offer->loadMissing('flight.cabins'),
                'hotel' => $offer->loadMissing('hotel.rooms.pricings'),
                default => $offer->loadMissing($relation),
            };
        }

        if ($offer->type === 'flight' && (! $offer->flight || ! $offer->flight->appears_in_web)) {
            return null;
        }

        if (
            $offer->type === 'excursion'
            && $this->platformSettingsService->get('excursion_visibility_controls_enabled', false) === true
            && (! $offer->excursion || ! $offer->excursion->appears_in_web)
        ) {
            return null;
        }

        $normalized = $this->normalizationService->normalize($offer, true, $lang);
        if ($normalized === null) {
            return null;
        }

        $pricing = app(PriceCalculatorService::class)->normalizedPrice($offer->price, $offer->currency);

        $payload = [
            'offer' => [
                'id' => $offer->id,
                'type' => $offer->type,
                'title' => $offer->getTranslated('title', $lang) ?? $offer->title,
                'price' => $pricing['calculated_price'],
                'base_price' => $pricing['base_price'],
                'calculated_price' => $pricing['calculated_price'],
                'currency' => $offer->currency,
                'pricing' => $pricing,
                'status' => $offer->status,
                'company_id' => $offer->company_id,
            ],
            'normalized' => $normalized,
        ];

        if ($offer->type === 'hotel' && $offer->hotel instanceof Hotel) {
            $payload['hotel_rooms'] = $this->hotelRoomsForPublic($offer->hotel);
        }

        $reviewTarget = $this->reviewTargetForOffer($offer);
        if ($reviewTarget !== null) {
            $payload['review_target'] = $reviewTarget;
        }

        return $payload;
    }

    /**
     * Module row id for {@see \App\Models\Review} targets (aligned with `Review::TARGET_ENTITY_TYPES`).
     *
     * @return array{entity_type: string, entity_id: int}|null
     */
    private function reviewTargetForOffer(Offer $offer): ?array
    {
        $entityId = match ($offer->type) {
            'hotel' => $offer->hotel?->id,
            'package' => $offer->package?->id,
            'flight' => $offer->flight?->id,
            'transfer' => $offer->transfer?->id,
            'excursion' => $offer->excursion?->id,
            'car' => $offer->car?->id,
            default => null,
        };

        if ($entityId === null) {
            return null;
        }

        return [
            'entity_type' => $offer->type,
            'entity_id' => (int) $entityId,
        ];
    }

    /**
     * B2C-safe room rows for discovery offer detail (no operator-only fields).
     *
     * @return list<array<string, mixed>>
     */
    private function hotelRoomsForPublic(Hotel $hotel): array
    {
        if (! $hotel->relationLoaded('rooms')) {
            return [];
        }

        return $hotel->rooms->map(function (HotelRoom $room) {
            return [
                'id' => $room->id,
                'room_type' => $room->room_type,
                'room_name' => $room->room_name,
                'max_adults' => (int) $room->max_adults,
                'max_children' => (int) $room->max_children,
                'bed_type' => $room->bed_type,
                'bed_count' => (int) $room->bed_count,
                'room_size' => $room->room_size,
                'pricings' => $room->relationLoaded('pricings')
                    ? $room->pricings->map(fn ($p) => [
                        'price' => (float) $p->price,
                        'currency' => $p->currency,
                        'pricing_mode' => $p->pricing_mode,
                        'min_nights' => $p->min_nights !== null ? (int) $p->min_nights : null,
                    ])->values()->all()
                    : [],
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyOfferPriceCurrencyFilters(Builder $query, array $input): void
    {
        if (isset($input['price_min']) && $input['price_min'] !== null && $input['price_min'] !== '') {
            $query->where('price', '>=', (float) $input['price_min']);
        }
        if (isset($input['price_max']) && $input['price_max'] !== null && $input['price_max'] !== '') {
            $query->where('price', '<=', (float) $input['price_max']);
        }
        if (isset($input['currency']) && $input['currency'] !== null && $input['currency'] !== '') {
            $query->where('currency', strtoupper((string) $input['currency']));
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyLocationAndModuleFilters(Builder $query, array $input, ?string $moduleType): void
    {
        $from = $this->nullableString($input['from_location'] ?? null);
        $to = $this->nullableString($input['to_location'] ?? null);
        $destination = $this->nullableString($input['destination'] ?? null);

        $isDirect = $input['is_direct'] ?? null;
        $hasBaggage = $input['has_baggage'] ?? null;
        $cabinClass = $this->nullableString($input['cabin_class'] ?? null);
        $stars = $input['stars'] ?? null;
        $mealType = $this->nullableString($input['meal_type'] ?? null);
        $minRating = $input['min_rating'] ?? null;
        $vehicleType = $this->nullableString($input['vehicle_type'] ?? null);
        $privateOnly = $input['private_only'] ?? null;
        $isPackageEligible = $input['is_package_eligible'] ?? null;

        if ($moduleType === 'flight' || $moduleType === null) {
            $this->applyFlightLocationModuleConstraints($query, $moduleType, $from, $to, $isDirect, $hasBaggage, $cabinClass, $isPackageEligible);
        }

        if ($moduleType === 'hotel' || $moduleType === null) {
            $this->applyHotelDestinationModuleConstraints(
                $query,
                $moduleType,
                $from,
                $to,
                $destination,
                $stars,
                $mealType,
                $minRating,
                $isPackageEligible
            );
        }

        if ($moduleType === 'transfer' || $moduleType === null) {
            $this->applyTransferLocationModuleConstraints($query, $moduleType, $from, $to, $vehicleType, $privateOnly, $isPackageEligible);
        }

        if ($moduleType === 'excursion' || $moduleType === null) {
            $this->applyExcursionLocationModuleConstraints($query, $moduleType, $from, $to, $destination);
        }

        if ($moduleType === 'package' || $moduleType === null) {
            $this->applyPackageDestinationConstraints($query, $moduleType, $destination, $isPackageEligible);
        }
    }

    private function applyFlightLocationModuleConstraints(
        Builder $query,
        ?string $moduleType,
        ?string $from,
        ?string $to,
        mixed $isDirect,
        mixed $hasBaggage,
        ?string $cabinClass,
        mixed $isPackageEligible
    ): void {
        $flightFilter = function (Builder $q) use ($from, $to, $isDirect, $hasBaggage, $cabinClass, $isPackageEligible, $moduleType): void {
            $q->where('appears_in_web', true);
            if ($from !== null) {
                $q->where('departure_city', 'like', $this->likeWrap($from));
            }
            if ($to !== null) {
                $q->where('arrival_city', 'like', $this->likeWrap($to));
            }
            if ($cabinClass !== null) {
                $q->where('cabin_class', $cabinClass);
            }
            if ($isDirect === true) {
                $q->where('connection_type', 'direct');
            } elseif ($isDirect === false) {
                $q->where('connection_type', 'connected');
            }
            if ($hasBaggage === true) {
                $q->where(function (Builder $q2): void {
                    $q2->where('hand_baggage_included', true)
                        ->orWhere('checked_baggage_included', true);
                });
            } elseif ($hasBaggage === false) {
                $q->where('hand_baggage_included', false)
                    ->where('checked_baggage_included', false);
            }
            if ($moduleType === 'flight' && $isPackageEligible !== null) {
                $q->where('is_package_eligible', (bool) $isPackageEligible);
            }
        };

        if ($moduleType === 'flight') {
            $query->whereHas('flight', $flightFilter);

            return;
        }

        if ($moduleType !== null) {
            return;
        }

        $needsCrossFlight = $from !== null || $to !== null || $cabinClass !== null
            || $isDirect !== null || $hasBaggage !== null;

        if (! $needsCrossFlight) {
            return;
        }

        $query->where(function (Builder $outer) use ($flightFilter): void {
            $outer->where(function (Builder $q) use ($flightFilter): void {
                $q->where('type', 'flight')->whereHas('flight', $flightFilter);
            })
            ->orWhere(function (Builder $q): void {
                $q->where('type', 'hotel')->whereHas('hotel', fn (Builder $h) => $h->where('appears_in_web', true));
            })
            ->orWhere(function (Builder $q): void {
                $q->where('type', 'transfer')->whereHas('transfer', fn (Builder $t) => $t->where('appears_in_web', true));
            })
            ->orWhere(function (Builder $q): void {
                $q->where('type', 'car')->whereHas('car', fn (Builder $c) => $c->where('appears_in_web', true));
            })
            ->orWhere(function (Builder $q): void {
                $q->where('type', 'excursion')->whereHas('excursion', fn (Builder $e) => $e->where('appears_in_web', true));
            });
        });
    }

    private function applyHotelDestinationModuleConstraints(
        Builder $query,
        ?string $moduleType,
        ?string $from,
        ?string $to,
        ?string $destination,
        mixed $stars,
        ?string $mealType,
        mixed $minRating,
        mixed $isPackageEligible
    ): void {
        $applyHotelVisibilityControls = $this->platformSettingsService->get(
            'hotel_visibility_controls_enabled',
            false
        ) === true;

        $allowedHotelIds = null;
        $applyHotelServiceConnectionsIntegration = $this->platformSettingsService->get(
            'hotel_service_connections_integration_enabled',
            false
        ) === true;
        if (
            $applyHotelServiceConnectionsIntegration === true
            && $moduleType === 'hotel'
            && $from !== null
            && $to !== null
            && trim($from) !== ''
            && trim($to) !== ''
        ) {
            $resolved = $this->resolveAllowedHotelIdsFromAcceptedServiceConnections($from, $to);
            // Safe rollout: if no matches, keep existing behavior (no targeting restriction).
            if ($resolved !== []) {
                $allowedHotelIds = $resolved;
            }
        }

        $hotelFilter = function (Builder $q) use ($destination, $stars, $mealType, $minRating, $isPackageEligible, $moduleType, $applyHotelVisibilityControls, $allowedHotelIds): void {
            if ($applyHotelVisibilityControls) {
                // Public discovery context.
                $this->offerVisibilityService->applyVisibilityFilter($q, 'web');
            }

            if ($allowedHotelIds !== null) {
                $q->whereIn($q->getModel()->getTable().'.id', $allowedHotelIds);
            }

            if ($destination !== null) {
                $like = $this->likeWrap($destination);
                $q->where(function (Builder $hq) use ($like): void {
                    $hq->where('city', 'like', $like)
                        ->orWhere('country', 'like', $like);
                });
            }
            $q->where('availability_status', 'available');
            if ($stars !== null) {
                $q->where('star_rating', '>=', (int) $stars);
            }
            if ($mealType !== null) {
                $q->where('meal_type', $mealType);
            }
            if ($minRating !== null) {
                $q->where('review_score', '>=', (float) $minRating);
            }
            if ($moduleType === 'hotel' && $isPackageEligible !== null) {
                $q->where('is_package_eligible', (bool) $isPackageEligible);
            }
        };

        if ($moduleType === 'hotel') {
            $query->whereHas('hotel', $hotelFilter);

            return;
        }

        if ($moduleType !== null) {
            return;
        }

        $needsCrossHotel = $destination !== null || $stars !== null || $mealType !== null || $minRating !== null;

        if (! $needsCrossHotel) {
            return;
        }

        $query->where(function (Builder $outer) use ($hotelFilter): void {
            $outer->where(function (Builder $q) use ($hotelFilter): void {
                $q->where('type', 'hotel')->whereHas('hotel', $hotelFilter);
            })->orWhere('type', '!=', 'hotel');
        });
    }

    private function applyTransferLocationModuleConstraints(
        Builder $query,
        ?string $moduleType,
        ?string $from,
        ?string $to,
        ?string $vehicleType,
        mixed $privateOnly,
        mixed $isPackageEligible
    ): void {
        $applyTransferVisibilityControls = $this->platformSettingsService->get(
            'transfer_visibility_controls_enabled',
            false
        ) === true;

        $allowedTransferIds = null;
        $applyTransferServiceConnectionsIntegration = $this->platformSettingsService->get(
            'transfer_service_connections_integration_enabled',
            false
        ) === true;
        if (
            $applyTransferServiceConnectionsIntegration === true
            && $moduleType === 'transfer'
            && $from !== null
            && $to !== null
            && trim($from) !== ''
            && trim($to) !== ''
        ) {
            $resolved = $this->resolveAllowedTransferIdsFromAcceptedServiceConnections($from, $to);
            // Safe rollout: if no matches, keep existing behavior (no targeting restriction).
            if ($resolved !== []) {
                $allowedTransferIds = $resolved;
            }
        }

        $transferFilter = function (Builder $q) use ($from, $to, $vehicleType, $privateOnly, $isPackageEligible, $moduleType, $applyTransferVisibilityControls, $allowedTransferIds): void {
            if ($applyTransferVisibilityControls) {
                // Public discovery context.
                $this->offerVisibilityService->applyVisibilityFilter($q, 'web');
                $q->where('appears_in_web', true);
            }

            if ($allowedTransferIds !== null) {
                $q->whereIn($q->getModel()->getTable().'.id', $allowedTransferIds);
            }

            if ($from !== null) {
                $q->where('pickup_city', 'like', $this->likeWrap($from));
            }
            if ($to !== null) {
                $q->where('dropoff_city', 'like', $this->likeWrap($to));
            }
            if ($vehicleType !== null) {
                $q->where('vehicle_category', $vehicleType);
            }
            if ($privateOnly === true) {
                $q->where('private_or_shared', 'private');
            } elseif ($privateOnly === false) {
                $q->where('private_or_shared', 'shared');
            }
            if ($moduleType === 'transfer' && $isPackageEligible !== null) {
                $q->where('is_package_eligible', (bool) $isPackageEligible);
            }
        };

        if ($moduleType === 'transfer') {
            $query->whereHas('transfer', $transferFilter);

            return;
        }

        if ($moduleType !== null) {
            return;
        }

        $needsCrossTransfer = $from !== null || $to !== null || $vehicleType !== null || $privateOnly !== null;

        if (! $needsCrossTransfer) {
            return;
        }

        $query->where(function (Builder $outer) use ($transferFilter): void {
            $outer->where(function (Builder $q) use ($transferFilter): void {
                $q->where('type', 'transfer')->whereHas('transfer', $transferFilter);
            })->orWhere('type', '!=', 'transfer');
        });
    }

    /**
     * @return list<int> Allowed transfer ids derived from accepted service_connections.
     */
    private function resolveAllowedTransferIdsFromAcceptedServiceConnections(string $from, string $to): array
    {
        $reqCities = $this->uniqueNormalizedCities([$from, $to]);
        if ($reqCities === []) {
            return [];
        }

        $connections = ServiceConnection::query()
            ->where('status', 'accepted')
            ->where('client_targeting', 'all')
            ->whereNotNull('city_rules')
            ->where(function (Builder $q): void {
                // flight|hotel => transfer
                $q->where(function (Builder $q2): void {
                    $q2->whereIn('source_type', ['flight', 'hotel'])
                        ->where('target_type', 'transfer');
                })
                    // transfer => flight|hotel (only when connection_type is "both")
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('source_type', 'transfer')
                            ->whereIn('target_type', ['flight', 'hotel'])
                            ->where('connection_type', 'both');
                    });
            })
            ->get();

        $allowed = [];

        foreach ($connections as $c) {
            if (! is_array($c->city_rules) || $c->city_rules === []) {
                continue;
            }

            $rules = $c->city_rules;
            $mode = is_string($rules['mode'] ?? null) ? $rules['mode'] : 'any';
            $sourceCities = is_array($rules['source_cities'] ?? null) ? $rules['source_cities'] : [];
            $targetCities = is_array($rules['target_cities'] ?? null) ? $rules['target_cities'] : [];

            // Direction: flight|hotel => transfer (match transfer cities = target_cities)
            if (in_array($c->source_type, ['flight', 'hotel'], true) && $c->target_type === 'transfer') {
                $expectedRouteCities = $targetCities;
                if ($this->routeCitiesMatch($mode, $reqCities, $expectedRouteCities)) {
                    $allowed[] = (int) $c->target_id;
                }

                continue;
            }

            // Direction: transfer => flight|hotel (only for connection_type="both"; match transfer cities = source_cities)
            if ($c->source_type === 'transfer' && in_array($c->target_type, ['flight', 'hotel'], true) && $c->connection_type === 'both') {
                $expectedRouteCities = $sourceCities;
                if ($this->routeCitiesMatch($mode, $reqCities, $expectedRouteCities)) {
                    $allowed[] = (int) $c->source_id;
                }
            }
        }

        $allowed = array_values(array_unique(array_filter($allowed, fn ($id) => is_numeric($id) && (int) $id > 0)));

        return $allowed;
    }

    /**
     * Excursion discovery: visibility + optional service_connections targeting (same rollout flags as transfers).
     */
    private function applyExcursionLocationModuleConstraints(
        Builder $query,
        ?string $moduleType,
        ?string $from,
        ?string $to,
        ?string $destination,
    ): void {
        $applyExcursionVisibilityControls = $this->platformSettingsService->get(
            'excursion_visibility_controls_enabled',
            false
        ) === true;

        $allowedExcursionIds = null;
        $applyExcursionServiceConnectionsIntegration = $this->platformSettingsService->get(
            'excursion_service_connections_integration_enabled',
            false
        ) === true;
        if (
            $applyExcursionServiceConnectionsIntegration === true
            && $moduleType === 'excursion'
            && $from !== null
            && $to !== null
            && trim($from) !== ''
            && trim($to) !== ''
        ) {
            $resolved = $this->resolveAllowedExcursionIdsFromAcceptedServiceConnections($from, $to);
            if ($resolved !== []) {
                $allowedExcursionIds = $resolved;
            }
        }

        $excursionFilter = function (Builder $q) use (
            $from,
            $to,
            $destination,
            $applyExcursionVisibilityControls,
            $allowedExcursionIds
        ): void {
            if ($applyExcursionVisibilityControls) {
                $this->offerVisibilityService->applyVisibilityFilter($q, 'web');
                $q->where('appears_in_web', true);
            }

            if ($allowedExcursionIds !== null) {
                $q->whereIn($q->getModel()->getTable().'.id', $allowedExcursionIds);
            }

            $loc = $destination ?? $from ?? $to;
            if ($loc !== null && trim($loc) !== '') {
                $like = $this->likeWrap($loc);
                $table = $q->getModel()->getTable();
                $q->where(function (Builder $inner) use ($table, $like): void {
                    $inner->where($table.'.city', 'like', $like)
                        ->orWhere($table.'.country', 'like', $like)
                        ->orWhere($table.'.location', 'like', $like);
                });
            }
        };

        if ($moduleType === 'excursion') {
            $query->whereHas('excursion', $excursionFilter);

            return;
        }

        if ($moduleType !== null) {
            return;
        }

        $needsCrossExcursion = $from !== null || $to !== null || $destination !== null;

        if (! $needsCrossExcursion) {
            return;
        }

        $query->where(function (Builder $outer) use ($excursionFilter): void {
            $outer->where(function (Builder $q) use ($excursionFilter): void {
                $q->where('type', 'excursion')->whereHas('excursion', $excursionFilter);
            })->orWhere('type', '!=', 'excursion');
        });
    }

    /**
     * @return list<int> Allowed excursion ids derived from accepted service_connections.
     */
    private function resolveAllowedExcursionIdsFromAcceptedServiceConnections(string $from, string $to): array
    {
        $reqCities = $this->uniqueNormalizedCities([$from, $to]);
        if ($reqCities === []) {
            return [];
        }

        $connections = ServiceConnection::query()
            ->where('status', 'accepted')
            ->where('client_targeting', 'all')
            ->whereNotNull('city_rules')
            ->where(function (Builder $q): void {
                $q->where(function (Builder $q2): void {
                    $q2->whereIn('source_type', ['flight', 'hotel'])
                        ->where('target_type', 'excursion');
                })
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('source_type', 'excursion')
                            ->whereIn('target_type', ['flight', 'hotel'])
                            ->where('connection_type', 'both');
                    });
            })
            ->get();

        $allowed = [];

        foreach ($connections as $c) {
            if (! is_array($c->city_rules) || $c->city_rules === []) {
                continue;
            }

            $rules = $c->city_rules;
            $mode = is_string($rules['mode'] ?? null) ? $rules['mode'] : 'any';
            $sourceCities = is_array($rules['source_cities'] ?? null) ? $rules['source_cities'] : [];
            $targetCities = is_array($rules['target_cities'] ?? null) ? $rules['target_cities'] : [];

            if (in_array($c->source_type, ['flight', 'hotel'], true) && $c->target_type === 'excursion') {
                $expectedRouteCities = $targetCities;
                if ($this->routeCitiesMatch($mode, $reqCities, $expectedRouteCities)) {
                    $allowed[] = (int) $c->target_id;
                }

                continue;
            }

            if ($c->source_type === 'excursion' && in_array($c->target_type, ['flight', 'hotel'], true) && $c->connection_type === 'both') {
                $expectedRouteCities = $sourceCities;
                if ($this->routeCitiesMatch($mode, $reqCities, $expectedRouteCities)) {
                    $allowed[] = (int) $c->source_id;
                }
            }
        }

        $allowed = array_values(array_unique(array_filter($allowed, fn ($id) => is_numeric($id) && (int) $id > 0)));

        return $allowed;
    }

    private function applyPackageDestinationConstraints(
        Builder $query,
        ?string $moduleType,
        ?string $destination,
        mixed $isPackageEligible
    ): void {
        if ($moduleType === 'package') {
            $query->whereHas('package', function (Builder $q) use ($destination, $isPackageEligible): void {
                $q->where('is_public', true);
                if ($destination !== null) {
                    $like = $this->likeWrap($destination);
                    $q->where(function (Builder $pq) use ($like): void {
                        $pq->where('destination_city', 'like', $like)
                            ->orWhere('destination_country', 'like', $like);
                    });
                }
                if ($isPackageEligible !== null) {
                    $q->where('is_package_eligible', (bool) $isPackageEligible);
                }
            });

            return;
        }

        if ($moduleType !== null || $destination === null) {
            return;
        }

        $like = $this->likeWrap($destination);
        $query->where(function (Builder $outer) use ($like): void {
            $outer->where(function (Builder $q) use ($like): void {
                $q->where('type', 'package')
                    ->whereHas('package', function (Builder $pq) use ($like): void {
                        $pq->where('is_public', true)
                            ->where(function (Builder $p2) use ($like): void {
                                $p2->where('destination_city', 'like', $like)
                                    ->orWhere('destination_country', 'like', $like);
                            });
                    });
            })->orWhere('type', '!=', 'package');
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyDateRangeFilters(Builder $query, array $input, ?string $moduleType): void
    {
        $startDate = $this->nullableString($input['start_date'] ?? null);
        $endDate = $this->nullableString($input['end_date'] ?? null);

        if ($startDate === null && $endDate === null) {
            return;
        }

        $start = $startDate !== null ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate !== null ? Carbon::parse($endDate)->endOfDay() : null;

        if ($moduleType === 'flight') {
            $query->whereHas('flight', fn (Builder $q) => $this->applyFlightDateRange($q, $start, $end));

            return;
        }

        if ($moduleType === 'transfer') {
            $query->whereHas('transfer', fn (Builder $q) => $this->applyTransferDateRange($q, $start, $end));

            return;
        }

        if ($moduleType === 'car') {
            $query->whereHas('car', fn (Builder $q) => $this->applyCarDateRange($q, $start, $end));

            return;
        }

        if ($moduleType === 'excursion') {
            $query->whereHas('excursion', fn (Builder $q) => $this->applyExcursionDateRange($q, $start, $end));

            return;
        }

        if ($moduleType === 'hotel') {
            $query->whereHas('hotel', fn (Builder $q) => $this->applyHotelDateRange($q, $start, $end));

            return;
        }

        if ($moduleType === 'package') {
            $query->whereHas('package', fn (Builder $q) => $this->applyRecordCreatedAtDateRange($q, $start, $end));

            return;
        }

        if ($moduleType === 'visa') {
            $query->whereHas('visa', fn (Builder $q) => $this->applyRecordCreatedAtDateRange($q, $start, $end));

            return;
        }

        if ($moduleType === null) {
            $query->where(function (Builder $outer) use ($start, $end): void {
                $outer->where(function (Builder $q) use ($start, $end): void {
                    $q->where('type', 'flight')
                        ->whereHas('flight', fn (Builder $fq) => $this->applyFlightDateRange($fq, $start, $end));
                })->orWhere(function (Builder $q) use ($start, $end): void {
                    $q->where('type', 'transfer')
                        ->whereHas('transfer', fn (Builder $tq) => $this->applyTransferDateRange($tq, $start, $end));
                })->orWhere(function (Builder $q) use ($start, $end): void {
                    $q->where('type', 'car')
                        ->whereHas('car', fn (Builder $cq) => $this->applyCarDateRange($cq, $start, $end));
                })->orWhere(function (Builder $q) use ($start, $end): void {
                    $q->where('type', 'excursion')
                        ->whereHas('excursion', fn (Builder $eq) => $this->applyExcursionDateRange($eq, $start, $end));
                })->orWhere(function (Builder $q) use ($start, $end): void {
                    $q->where('type', 'hotel')
                        ->whereHas('hotel', fn (Builder $hq) => $this->applyHotelDateRange($hq, $start, $end));
                })->orWhere(function (Builder $q) use ($start, $end): void {
                    $q->where('type', 'package')
                        ->whereHas('package', fn (Builder $pq) => $this->applyRecordCreatedAtDateRange($pq, $start, $end));
                })->orWhere(function (Builder $q) use ($start, $end): void {
                    $q->where('type', 'visa')
                        ->whereHas('visa', fn (Builder $vq) => $this->applyRecordCreatedAtDateRange($vq, $start, $end));
                });
            });
        }
    }

    private function applyFlightDateRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where('departure_at', '>=', $start);
        }
        if ($end !== null) {
            $query->where('arrival_at', '<=', $end);
        }
    }

    private function applyTransferDateRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where(function (Builder $q) use ($start): void {
                $q->whereDate('service_date', '>=', $start)
                    ->orWhere('availability_window_end', '>=', $start)
                    ->orWhereNull('availability_window_end');
            });
        }
        if ($end !== null) {
            $query->where(function (Builder $q) use ($end): void {
                $q->whereDate('service_date', '<=', $end)
                    ->orWhere('availability_window_start', '<=', $end)
                    ->orWhereNull('availability_window_start');
            });
        }
    }

    private function applyCarDateRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where(function (Builder $q) use ($start): void {
                $q->whereNull('availability_window_end')
                    ->orWhere('availability_window_end', '>=', $start);
            });
        }
        if ($end !== null) {
            $query->where(function (Builder $q) use ($end): void {
                $q->whereNull('availability_window_start')
                    ->orWhere('availability_window_start', '<=', $end);
            });
        }
    }

    private function applyExcursionDateRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where(function (Builder $q) use ($start): void {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $start);
            });
        }
        if ($end !== null) {
            $query->where(function (Builder $q) use ($end): void {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $end);
            });
        }
    }

    private function applyHotelDateRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        $query->whereHas('rooms.pricings', function (Builder $pricing) use ($start, $end): void {
            if ($start !== null) {
                $pricing->where(function (Builder $q) use ($start): void {
                    $q->whereNull('valid_to')
                        ->orWhereDate('valid_to', '>=', $start);
                });
            }
            if ($end !== null) {
                $pricing->where(function (Builder $q) use ($end): void {
                    $q->whereNull('valid_from')
                        ->orWhereDate('valid_from', '<=', $end);
                });
            }
        });
    }

    private function applyRecordCreatedAtDateRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where('created_at', '>=', $start);
        }
        if ($end !== null) {
            $query->where('created_at', '<=', $end);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyFreeCancellationFilter(Builder $query, array $input, ?string $moduleType): void
    {
        $fc = $input['free_cancellation'] ?? null;
        if ($fc === null) {
            return;
        }

        $bool = (bool) $fc;

        if ($moduleType === 'flight') {
            $query->whereHas('flight', function (Builder $q) use ($bool): void {
                if ($bool) {
                    $q->where('cancellation_policy_type', 'fully_refundable');
                } else {
                    $q->where('cancellation_policy_type', '!=', 'fully_refundable');
                }
            });

            return;
        }

        if ($moduleType === 'hotel') {
            $query->whereHas('hotel', function (Builder $q) use ($bool): void {
                $q->where('free_cancellation', $bool);
            });

            return;
        }

        if ($moduleType === 'transfer') {
            $query->whereHas('transfer', function (Builder $q) use ($bool): void {
                $q->where('free_cancellation', $bool);
            });

            return;
        }

        if ($moduleType === null) {
            $query->where(function (Builder $outer) use ($bool): void {
                $outer->where(function (Builder $q) use ($bool): void {
                    $q->where('type', 'flight')
                        ->whereHas('flight', function (Builder $fq) use ($bool): void {
                            if ($bool) {
                                $fq->where('cancellation_policy_type', 'fully_refundable');
                            } else {
                                $fq->where('cancellation_policy_type', '!=', 'fully_refundable');
                            }
                        });
                })->orWhere(function (Builder $q) use ($bool): void {
                    $q->where('type', 'hotel')
                        ->whereHas('hotel', fn (Builder $hq) => $hq->where('free_cancellation', $bool));
                })->orWhere(function (Builder $q) use ($bool): void {
                    $q->where('type', 'transfer')
                        ->whereHas('transfer', fn (Builder $tq) => $tq->where('free_cancellation', $bool));
                })->orWhereNotIn('type', ['flight', 'hotel', 'transfer']);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyPackageEligibleFilter(Builder $query, array $input, ?string $moduleType): void
    {
        $ipe = $input['is_package_eligible'] ?? null;
        if ($ipe === null) {
            return;
        }

        $bool = (bool) $ipe;

        if ($moduleType !== null) {
            return;
        }

        if (! $bool) {
            return;
        }

        $query->where(function (Builder $q): void {
            $q->whereHas('flight', fn (Builder $fq) => $fq->where('is_package_eligible', true))
                ->orWhereHas('hotel', fn (Builder $hq) => $hq->where('is_package_eligible', true))
                ->orWhereHas('transfer', fn (Builder $tq) => $tq->where('is_package_eligible', true))
                ->orWhereHas('package', fn (Builder $pq) => $pq->where('is_package_eligible', true));
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyHistorySearchFilters(Builder $query, array $input, ?string $moduleType): void
    {
        $country = $this->nullableString($input['country_id'] ?? null);
        $city = $this->nullableString($input['city_id'] ?? null);
        $hotelName = $this->nullableString($input['hotel_name'] ?? null);
        $orderNumber = $this->nullableString($input['order_number'] ?? null);
        $freeCancellation = $this->parseBooleanInput($input['is_free_cancellation'] ?? null);
        $checkIn = $this->nullableString($input['date_start'] ?? ($input['start_date'] ?? null));
        $checkOut = $this->nullableString($input['date_end'] ?? ($input['end_date'] ?? null));

        if ($country !== null) {
            $this->applyCountryFilter($query, $moduleType, $country);
        }

        if ($city !== null) {
            $this->applyCityFilter($query, $moduleType, $city);
        }

        if ($hotelName !== null) {
            $this->applyHotelNameFilter($query, $moduleType, $hotelName);
        }

        if ($orderNumber !== null) {
            $this->applyOrderNumberFilter($query, $orderNumber);
        }

        if ($freeCancellation !== null) {
            $this->applyInvoiceFreeCancellationFilter($query, $freeCancellation);
        }

        if ($checkIn !== null || $checkOut !== null) {
            $this->applyInvoiceCheckInOutFilter($query, $checkIn, $checkOut);
        }
    }

    private function applyCountryFilter(Builder $query, ?string $moduleType, string $country): void
    {
        if ($moduleType !== null && $moduleType !== 'hotel' && $moduleType !== 'package') {
            return;
        }

        $like = $this->likeWrap($country);
        $query->where(function (Builder $q) use ($moduleType, $like): void {
            if ($moduleType === 'hotel') {
                $q->whereHas('hotel', fn (Builder $hq) => $hq->where('country', 'like', $like));

                return;
            }

            if ($moduleType === 'package') {
                $q->whereHas('package', fn (Builder $pq) => $pq->where('destination_country', 'like', $like));

                return;
            }

            $q->where(function (Builder $hq) use ($like): void {
                $hq->where('type', 'hotel')->whereHas('hotel', fn (Builder $hotel) => $hotel->where('country', 'like', $like));
            })->orWhere(function (Builder $pq) use ($like): void {
                $pq->where('type', 'package')->whereHas('package', fn (Builder $package) => $package->where('destination_country', 'like', $like));
            });
        });
    }

    private function applyCityFilter(Builder $query, ?string $moduleType, string $city): void
    {
        if ($moduleType !== null && $moduleType !== 'hotel' && $moduleType !== 'package') {
            return;
        }

        $like = $this->likeWrap($city);
        $query->where(function (Builder $q) use ($moduleType, $like): void {
            if ($moduleType === 'hotel') {
                $q->whereHas('hotel', fn (Builder $hq) => $hq->where('city', 'like', $like));

                return;
            }

            if ($moduleType === 'package') {
                $q->whereHas('package', fn (Builder $pq) => $pq->where('destination_city', 'like', $like));

                return;
            }

            $q->where(function (Builder $hq) use ($like): void {
                $hq->where('type', 'hotel')->whereHas('hotel', fn (Builder $hotel) => $hotel->where('city', 'like', $like));
            })->orWhere(function (Builder $pq) use ($like): void {
                $pq->where('type', 'package')->whereHas('package', fn (Builder $package) => $package->where('destination_city', 'like', $like));
            });
        });
    }

    private function applyHotelNameFilter(Builder $query, ?string $moduleType, string $hotelName): void
    {
        if ($moduleType !== null && $moduleType !== 'hotel') {
            return;
        }

        $like = $this->likeWrap($hotelName);
        if ($moduleType === 'hotel') {
            $query->whereHas('hotel', fn (Builder $q) => $q->where('hotel_name', 'like', $like));

            return;
        }

        $query->where(function (Builder $q) use ($like): void {
            $q->where(function (Builder $hq) use ($like): void {
                $hq->where('type', 'hotel')->whereHas('hotel', fn (Builder $hotel) => $hotel->where('hotel_name', 'like', $like));
            })->orWhereHas('bookingItems.booking.invoices', fn (Builder $iq) => $iq->where('hotel_name', 'like', $like));
        });
    }

    private function applyOrderNumberFilter(Builder $query, string $orderNumber): void
    {
        $like = $this->likeWrap($orderNumber);
        $query->whereHas('bookingItems.booking', function (Builder $bq) use ($orderNumber, $like): void {
            $bq->where(function (Builder $wq) use ($orderNumber, $like): void {
                if (is_numeric($orderNumber)) {
                    $wq->where('id', (int) $orderNumber);
                }

                $wq->orWhereHas('invoices', function (Builder $iq) use ($orderNumber, $like): void {
                    if (is_numeric($orderNumber)) {
                        $iq->where('id', (int) $orderNumber);
                    }

                    $iq->orWhere('unique_booking_reference', 'like', $like)
                        ->orWhere('hotel_order_id', 'like', $like);
                });
            });
        });
    }

    private function applyInvoiceFreeCancellationFilter(Builder $query, bool $freeCancellation): void
    {
        $query->whereHas('bookingItems.booking.invoices', function (Builder $iq) use ($freeCancellation): void {
            $iq->where('cancellation_without_penalty', $freeCancellation);
        });
    }

    private function applyInvoiceCheckInOutFilter(Builder $query, ?string $checkIn, ?string $checkOut): void
    {
        $query->whereHas('bookingItems.booking.invoices', function (Builder $iq) use ($checkIn, $checkOut): void {
            if ($checkIn !== null) {
                $iq->whereDate('check_in', '>=', Carbon::parse($checkIn)->startOfDay());
            }
            if ($checkOut !== null) {
                $iq->whereDate('check_out', '<=', Carbon::parse($checkOut)->endOfDay());
            }
        });
    }

    private function relationNameForType(string $type): ?string
    {
        return match ($type) {
            'flight' => 'flight',
            'hotel' => 'hotel',
            'transfer' => 'transfer',
            'car' => 'car',
            'excursion' => 'excursion',
            'package' => 'package',
            'visa' => 'visa',
            default => null,
        };
    }

    /**
     * @return list<int> Allowed hotel ids derived from accepted service_connections.
     */
    private function resolveAllowedHotelIdsFromAcceptedServiceConnections(string $from, string $to): array
    {
        $reqCities = $this->uniqueNormalizedCities([$from, $to]);
        if ($reqCities === []) {
            return [];
        }

        $connections = ServiceConnection::query()
            ->where('status', 'accepted')
            ->where('client_targeting', 'all')
            ->whereNotNull('city_rules')
            ->where(function (Builder $q): void {
                // flight|transfer => hotel
                $q->where(function (Builder $q2): void {
                    $q2->whereIn('source_type', ['flight', 'transfer'])
                        ->where('target_type', 'hotel');
                })
                    // hotel => flight|transfer (only when connection_type is "both")
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('source_type', 'hotel')
                            ->whereIn('target_type', ['flight', 'transfer'])
                            ->where('connection_type', 'both');
                    });
            })
            ->get();

        $allowed = [];

        foreach ($connections as $c) {
            if (! is_array($c->city_rules) || $c->city_rules === []) {
                continue;
            }

            $rules = $c->city_rules;
            $mode = is_string($rules['mode'] ?? null) ? $rules['mode'] : 'any';
            $sourceCities = is_array($rules['source_cities'] ?? null) ? $rules['source_cities'] : [];
            $targetCities = is_array($rules['target_cities'] ?? null) ? $rules['target_cities'] : [];

            // Direction: flight|transfer => hotel
            if (in_array($c->source_type, ['flight', 'transfer'], true) && $c->target_type === 'hotel') {
                $expectedRouteCities = $sourceCities;
                if ($this->routeCitiesMatch($mode, $reqCities, $expectedRouteCities)) {
                    $allowed[] = (int) $c->target_id;
                }

                continue;
            }

            // Direction: hotel => flight|transfer (only for connection_type="both")
            if ($c->source_type === 'hotel' && in_array($c->target_type, ['flight', 'transfer'], true) && $c->connection_type === 'both') {
                $expectedRouteCities = $targetCities;
                if ($this->routeCitiesMatch($mode, $reqCities, $expectedRouteCities)) {
                    $allowed[] = (int) $c->source_id;
                }
            }
        }

        $allowed = array_values(array_unique(array_filter($allowed, fn ($id) => is_numeric($id) && (int) $id > 0)));

        return $allowed;
    }

    /**
     * Match requested route endpoint cities against service_connections city_rules.
     *
     * - mode="any": intersection is non-empty (fuzzy substring match).
     * - mode="exact": req and expected sets "mutually match" (both directions).
     *
     * @param  list<string>  $reqCities
     * @param  list<string>  $expectedCities
     */
    private function routeCitiesMatch(string $mode, array $reqCities, array $expectedCities): bool
    {
        $expectedCities = $this->uniqueNormalizedCities($expectedCities);
        if ($expectedCities === []) {
            return false;
        }

        $modeNorm = strtolower(trim($mode));

        $any = function () use ($reqCities, $expectedCities): bool {
            foreach ($reqCities as $reqCity) {
                foreach ($expectedCities as $expCity) {
                    if ($this->cityFuzzyMatch($reqCity, $expCity)) {
                        return true;
                    }
                }
            }

            return false;
        };

        if ($modeNorm === 'exact') {
            // Approximate set equality for route endpoints using fuzzy matching.
            foreach ($reqCities as $reqCity) {
                $matched = false;
                foreach ($expectedCities as $expCity) {
                    if ($this->cityFuzzyMatch($reqCity, $expCity)) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    return false;
                }
            }

            foreach ($expectedCities as $expCity) {
                $matched = false;
                foreach ($reqCities as $reqCity) {
                    if ($this->cityFuzzyMatch($reqCity, $expCity)) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    return false;
                }
            }

            return true;
        }

        return $any();
    }

    /**
     * @param  list<string>  $cities
     * @return list<string>
     */
    private function uniqueNormalizedCities(array $cities): array
    {
        $normalized = [];
        foreach ($cities as $c) {
            if (! is_string($c) && ! is_numeric($c)) {
                continue;
            }
            $s = mb_strtolower(trim((string) $c));
            if ($s === '') {
                continue;
            }
            $normalized[$s] = true;
        }

        return array_values(array_keys($normalized));
    }

    private function cityFuzzyMatch(string $requestCity, string $expectedCity): bool
    {
        $a = mb_strtolower(trim($requestCity));
        $b = mb_strtolower(trim($expectedCity));
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        return str_contains($b, $a) || str_contains($a, $b);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function likeWrap(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    private function parseBooleanInput(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'off'], true)) {
                return false;
            }
        }

        return null;
    }
}
