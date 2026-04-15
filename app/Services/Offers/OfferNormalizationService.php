<?php

namespace App\Services\Offers;

use App\Models\Car;
use App\Models\Excursion;
use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\Package;
use App\Models\Transfer;
use App\Models\Visa;
use App\Services\Cars\CarAdvancedOptionsNormalizer;
use App\Services\Pricing\PriceCalculatorService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Maps all offer module rows + parent {@see Offer} into one unified normalized offer shape.
 *
 * Missing module fields become null. Car advanced_options uses stored JSON or defaults.
 */
class OfferNormalizationService
{
    /**
     * Ordered keys for a deterministic JSON shape (all keys always present when normalized).
     *
     * @var list<string>
     */
    public const NORMALIZED_KEYS = [
        'offer_id',
        'module_type',
        'company_id',
        'title',
        'subtitle',
        'main_image',
        'rating',
        'review_count',
        'from_location',
        'to_location',
        'destination_location',
        'start_datetime',
        'end_datetime',
        'duration',
        'max_passengers',
        'min_passengers',
        'capacity_type',
        'price',
        'currency',
        'price_type',
        'availability_status',
        'available_quantity',
        'bookable',
        'free_cancellation',
        'refundable_type',
        'is_direct',
        'has_baggage',
        'meal_type',
        'stars',
        'vehicle_type',
        'is_package_eligible',
        'package_role',
        'advanced_options',
        'cabins',
    ];

    /**
     * @param  bool  $retailAsMainPrice  When true, normalized `price` is B2C (retail); when false, B2B (base).
     * @param  string|null  $languageCode  Resolved UI locale (e.g. from request); defaults to app default.
     * @return array<string, mixed>|null
     */
    public function normalize(Offer $offer, bool $retailAsMainPrice = false, ?string $languageCode = null): ?array
    {
        $lang = $languageCode ?? config('app.locale', 'en');

        return match ($offer->type) {
            'flight' => $this->normalizeFlightOffer($offer, $retailAsMainPrice, $lang),
            'hotel' => $this->normalizeHotelOffer($offer, $retailAsMainPrice, $lang),
            'transfer' => $this->normalizeTransferOffer($offer, $retailAsMainPrice, $lang),
            'car' => $this->normalizeCarOffer($offer, $retailAsMainPrice, $lang),
            'excursion' => $this->normalizeExcursionOffer($offer, $retailAsMainPrice, $lang),
            'package' => $this->normalizePackageOffer($offer, $retailAsMainPrice, $lang),
            'visa' => $this->normalizeVisaOffer($offer, $retailAsMainPrice, $lang),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFlightOffer(Offer $offer, bool $retailAsMainPrice = false, string $lang = 'en'): ?array
    {
        if (! $offer->relationLoaded('flight') || ! $offer->flight instanceof Flight) {
            return null;
        }

        $f = $offer->flight;

        $base = $this->baseFromOffer($offer, 'flight', $retailAsMainPrice, $lang);
        $base['from_location'] = $this->formatFlightEndpoint(
            $f->departure_airport,
            $f->departure_airport_code,
            $f->departure_city
        );
        $base['to_location'] = $this->formatFlightEndpoint(
            $f->arrival_airport,
            $f->arrival_airport_code,
            $f->arrival_city
        );
        $base['start_datetime'] = $this->toIso8601($f->departure_at);
        $base['end_datetime'] = $this->toIso8601($f->arrival_at);
        $base['duration'] = $f->duration_minutes;
        $base['max_passengers'] = $f->seat_capacity_total;
        $base['capacity_type'] = 'seat';
        $base['price_type'] = 'per_person';
        $base['available_quantity'] = $f->seat_capacity_available;
        $base['bookable'] = $f->reservation_allowed;
        $base['refundable_type'] = $f->cancellation_policy_type;
        $base['is_direct'] = $this->flightIsDirect($f->connection_type);
        $base['has_baggage'] = $this->flightHasBaggage($f);
        $base['is_package_eligible'] = $f->is_package_eligible;
        $base['package_role'] = 'flight';
        $base['cabins'] = $f->relationLoaded('cabins')
            ? $f->cabinsForApiResponse()
            : null;

        return $this->assertKeyOrder($base);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeHotelOffer(Offer $offer, bool $retailAsMainPrice = false, string $lang = 'en'): ?array
    {
        if (! $offer->relationLoaded('hotel') || ! $offer->hotel instanceof Hotel) {
            return null;
        }

        $h = $offer->hotel;

        $base = $this->baseFromOffer($offer, 'hotel', $retailAsMainPrice, $lang);
        $base['subtitle'] = $h->getTranslated('short_description', $lang) ?? $h->short_description;
        $base['main_image'] = $h->main_image;
        $base['rating'] = $h->review_score;
        $base['review_count'] = $h->review_count;
        $base['destination_location'] = $this->formatHotelDestination($h->city, $h->country);
        $base['capacity_type'] = 'room';
        $base['price_type'] = 'per_room';
        $base['availability_status'] = $h->availability_status;
        $base['bookable'] = $h->bookable;
        $base['free_cancellation'] = $h->free_cancellation;
        $base['refundable_type'] = $h->cancellation_policy_type;
        $base['meal_type'] = $h->meal_type;
        $base['stars'] = $h->star_rating;
        $base['is_package_eligible'] = $h->is_package_eligible;
        $base['package_role'] = 'stay';

        return $this->assertKeyOrder($base);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeTransferOffer(Offer $offer, bool $retailAsMainPrice = false, string $lang = 'en'): ?array
    {
        if (! $offer->relationLoaded('transfer') || ! $offer->transfer instanceof Transfer) {
            return null;
        }

        $t = $offer->transfer;

        $base = $this->baseFromOffer($offer, 'transfer', $retailAsMainPrice, $lang);
        $base['from_location'] = $this->formatTransferEndpoint(
            $t->pickup_point_name,
            $t->pickup_city,
            $t->pickup_country
        );
        $base['to_location'] = $this->formatTransferEndpoint(
            $t->dropoff_point_name,
            $t->dropoff_city,
            $t->dropoff_country
        );
        $base['start_datetime'] = $this->transferStartDatetime($t);
        $base['duration'] = $t->estimated_duration_minutes;
        $base['max_passengers'] = $t->maximum_passengers ?? $t->passenger_capacity;
        $base['min_passengers'] = $t->minimum_passengers;
        $base['capacity_type'] = 'vehicle';
        $base['price_type'] = $t->pricing_mode;
        $base['availability_status'] = $t->availability_status;
        $base['bookable'] = $t->bookable;
        $base['free_cancellation'] = $t->free_cancellation;
        $base['refundable_type'] = $t->cancellation_policy_type;
        $base['vehicle_type'] = $t->vehicle_category;
        $base['is_package_eligible'] = $t->is_package_eligible;
        $base['package_role'] = 'transfer';

        return $this->assertKeyOrder($base);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeCarOffer(Offer $offer, bool $retailAsMainPrice = false, string $lang = 'en'): ?array
    {
        if (! $offer->relationLoaded('car') || ! $offer->car instanceof Car) {
            return null;
        }

        $c = $offer->car;

        $base = $this->baseFromOffer($offer, 'car', $retailAsMainPrice, $lang);
        $base['from_location'] = $this->nullableNonEmptyString($c->pickup_location);
        $base['to_location'] = $this->nullableNonEmptyString($c->dropoff_location);
        $base['vehicle_type'] = $this->nullableNonEmptyString($c->vehicle_class);
        $base['advanced_options'] = app(CarAdvancedOptionsNormalizer::class)->forApi(
            is_array($c->advanced_options) ? $c->advanced_options : null
        );

        return $this->assertKeyOrder($base);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeExcursionOffer(Offer $offer, bool $retailAsMainPrice = false, string $lang = 'en'): ?array
    {
        if (! $offer->relationLoaded('excursion') || ! $offer->excursion instanceof Excursion) {
            return null;
        }

        $e = $offer->excursion;

        $base = $this->baseFromOffer($offer, 'excursion', $retailAsMainPrice, $lang);
        $base['destination_location'] = $this->nullableNonEmptyString($e->location);
        $base['duration'] = $this->nullableNonEmptyString($e->duration);
        $base['max_passengers'] = $e->group_size;

        return $this->assertKeyOrder($base);
    }

    /**
     * Shallow package row only — no composition or derived pricing.
     *
     * @return array<string, mixed>|null
     */
    private function normalizePackageOffer(Offer $offer, bool $retailAsMainPrice = false, string $lang = 'en'): ?array
    {
        if (! $offer->relationLoaded('package') || ! $offer->package instanceof Package) {
            return null;
        }

        $p = $offer->package;

        $base = $this->baseFromOffer($offer, 'package', $retailAsMainPrice, $lang);
        $subtitle = $p->package_subtitle !== null && $p->package_subtitle !== ''
            ? ($p->getTranslated('package_subtitle', $lang) ?? $p->package_subtitle)
            : (string) $p->package_type;
        $base['subtitle'] = $this->nullableNonEmptyString($subtitle);
        $base['destination_location'] = $this->nullableNonEmptyString($p->destination);
        $base['duration'] = $p->duration_days;
        $base['is_package_eligible'] = true;
        $base['package_role'] = 'package';

        return $this->assertKeyOrder($base);
    }

    /**
     * Shallow visa row only — no workflow or eligibility logic.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeVisaOffer(Offer $offer, bool $retailAsMainPrice = false, string $lang = 'en'): ?array
    {
        if (! $offer->relationLoaded('visa') || ! $offer->visa instanceof Visa) {
            return null;
        }

        $v = $offer->visa;

        $base = $this->baseFromOffer($offer, 'visa', $retailAsMainPrice, $lang);
        $base['destination_location'] = $this->nullableNonEmptyString($v->country);
        $base['subtitle'] = $this->nullableNonEmptyString($v->visa_type);
        $base['duration'] = $v->processing_days;

        return $this->assertKeyOrder($base);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseFromOffer(Offer $offer, string $moduleType, bool $retailAsMainPrice = false, string $lang = 'en'): array
    {
        $rawPrice = $offer->price ?? 0;
        $displayPrice = $retailAsMainPrice
            ? app(PriceCalculatorService::class)->b2cPrice($rawPrice)
            : $offer->price;

        return [
            'offer_id' => $offer->id,
            'module_type' => $moduleType,
            'company_id' => $offer->company_id,
            'title' => $offer->getTranslated('title', $lang) ?? $offer->title,
            'subtitle' => null,
            'main_image' => null,
            'rating' => null,
            'review_count' => null,
            'from_location' => null,
            'to_location' => null,
            'destination_location' => null,
            'start_datetime' => null,
            'end_datetime' => null,
            'duration' => null,
            'max_passengers' => null,
            'min_passengers' => null,
            'capacity_type' => null,
            'price' => $displayPrice,
            'currency' => $offer->currency,
            'price_type' => null,
            'availability_status' => null,
            'available_quantity' => null,
            'bookable' => null,
            'free_cancellation' => null,
            'refundable_type' => null,
            'is_direct' => null,
            'has_baggage' => null,
            'meal_type' => null,
            'stars' => null,
            'vehicle_type' => null,
            'is_package_eligible' => null,
            'package_role' => null,
            'cabins' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function assertKeyOrder(array $row): array
    {
        $ordered = [];
        foreach (self::NORMALIZED_KEYS as $key) {
            $ordered[$key] = $row[$key] ?? null;
        }

        return $ordered;
    }

    private function formatFlightEndpoint(?string $airport, ?string $code, ?string $city): ?string
    {
        $parts = array_values(array_unique(array_filter(
            [$airport, $code, $city],
            static fn ($v) => $v !== null && $v !== ''
        )));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function formatHotelDestination(?string $city, ?string $country): ?string
    {
        $parts = array_values(array_filter([$city, $country], static fn ($v) => $v !== null && $v !== ''));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function formatTransferEndpoint(?string $name, ?string $city, ?string $country): ?string
    {
        $parts = array_values(array_filter([$name, $city, $country], static fn ($v) => $v !== null && $v !== ''));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function nullableNonEmptyString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    private function toIso8601(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return null;
    }

    private function transferStartDatetime(Transfer $t): ?string
    {
        if ($t->service_date === null) {
            return null;
        }

        $datePart = $t->service_date instanceof CarbonInterface
            ? $t->service_date->format('Y-m-d')
            : (string) $t->service_date;

        $timePart = $this->transferTimeToHms($t->pickup_time);
        if ($timePart === null) {
            return null;
        }

        return Carbon::parse($datePart.' '.$timePart)->toIso8601String();
    }

    private function transferTimeToHms(mixed $pickupTime): ?string
    {
        if ($pickupTime === null || $pickupTime === '') {
            return null;
        }
        if ($pickupTime instanceof CarbonInterface) {
            return $pickupTime->format('H:i:s');
        }

        return (string) $pickupTime;
    }

    private function flightIsDirect(?string $connectionType): ?bool
    {
        return match ($connectionType) {
            'direct' => true,
            'connected' => false,
            default => null,
        };
    }

    private function flightHasBaggage(Flight $f): ?bool
    {
        $hand = $f->hand_baggage_included;
        $checked = $f->checked_baggage_included;

        if ($hand === true || $checked === true) {
            return true;
        }

        if ($hand === false && $checked === false) {
            return false;
        }

        return null;
    }
}
