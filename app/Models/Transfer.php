<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const TRANSFER_TYPES = [
        'airport_transfer',
        'hotel_transfer',
        'city_transfer',
        'private_transfer',
        'shared_transfer',
        'intercity_transfer',
    ];

    /** @var list<string> */
    public const POINT_TYPES = [
        'airport',
        'hotel',
        'address',
        'station',
        'port',
        'landmark',
    ];

    /** @var list<string> */
    public const VEHICLE_CATEGORIES = [
        'sedan',
        'suv',
        'minivan',
        'minibus',
        'bus',
        'luxury_car',
    ];

    /** @var list<string> */
    public const PRICING_MODES = [
        'per_vehicle',
        'per_person',
    ];

    /** @var list<string> */
    public const PRIVATE_OR_SHARED = [
        'private',
        'shared',
    ];

    protected $fillable = [
        'offer_id',
        'company_id',
        'visibility_rule',
        'transfer_title',
        'transfer_type',
        'pickup_country',
        'pickup_city',
        'pickup_point_type',
        'pickup_point_name',
        'dropoff_country',
        'dropoff_city',
        'dropoff_point_type',
        'dropoff_point_name',
        'pickup_latitude',
        'pickup_longitude',
        'dropoff_latitude',
        'dropoff_longitude',
        'route_distance_km',
        'route_label',
        'service_date',
        'pickup_time',
        'estimated_duration_minutes',
        'availability_window_start',
        'availability_window_end',
        'vehicle_category',
        'vehicle_class',
        'private_or_shared',
        'passenger_capacity',
        'luggage_capacity',
        'child_seat_available',
        'accessibility_support',
        'minimum_passengers',
        'maximum_passengers',
        'maximum_luggage',
        'child_seat_required_rule',
        'special_assistance_supported',
        'pricing_mode',
        'base_price',
        'free_cancellation',
        'cancellation_policy_type',
        'cancellation_deadline_at',
        'availability_status',
        'bookable',
        'is_package_eligible',
        'appears_in_packages',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'availability_window_start' => 'datetime',
            'availability_window_end' => 'datetime',
            'passenger_capacity' => 'integer',
            'luggage_capacity' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'minimum_passengers' => 'integer',
            'maximum_passengers' => 'integer',
            'maximum_luggage' => 'integer',
            'child_seat_available' => 'boolean',
            'accessibility_support' => 'boolean',
            'special_assistance_supported' => 'boolean',
            'free_cancellation' => 'boolean',
            'bookable' => 'boolean',
            'is_package_eligible' => 'boolean',
            'appears_in_packages' => 'boolean',
            'cancellation_deadline_at' => 'datetime',
            'pickup_latitude' => 'decimal:8',
            'pickup_longitude' => 'decimal:8',
            'dropoff_latitude' => 'decimal:8',
            'dropoff_longitude' => 'decimal:8',
            'route_distance_km' => 'decimal:2',
            'base_price' => 'decimal:2',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Compact module block for offer JSON (operator + catalog detail).
     *
     * @return array<string, mixed>
     */
    public function toOfferEmbedArray(): array
    {
        return [
            'id' => $this->id,
            'transfer_title' => $this->transfer_title,
            'transfer_type' => $this->transfer_type,
            'pickup_country' => $this->pickup_country,
            'pickup_city' => $this->pickup_city,
            'pickup_point_type' => $this->pickup_point_type,
            'pickup_point_name' => $this->pickup_point_name,
            'dropoff_country' => $this->dropoff_country,
            'dropoff_city' => $this->dropoff_city,
            'dropoff_point_type' => $this->dropoff_point_type,
            'dropoff_point_name' => $this->dropoff_point_name,
            'pickup_latitude' => $this->pickup_latitude,
            'pickup_longitude' => $this->pickup_longitude,
            'dropoff_latitude' => $this->dropoff_latitude,
            'dropoff_longitude' => $this->dropoff_longitude,
            'route_distance_km' => $this->route_distance_km,
            'route_label' => $this->route_label,
            'service_date' => $this->service_date?->format('Y-m-d'),
            'pickup_time' => $this->formatTimeForEmbed($this->pickup_time),
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'availability_window_start' => $this->availability_window_start?->toIso8601String(),
            'availability_window_end' => $this->availability_window_end?->toIso8601String(),
            'vehicle_category' => $this->vehicle_category,
            'vehicle_class' => $this->vehicle_class,
            'private_or_shared' => $this->private_or_shared,
            'passenger_capacity' => $this->passenger_capacity,
            'luggage_capacity' => $this->luggage_capacity,
            'child_seat_available' => (bool) $this->child_seat_available,
            'accessibility_support' => (bool) $this->accessibility_support,
            'minimum_passengers' => $this->minimum_passengers,
            'maximum_passengers' => $this->maximum_passengers,
            'maximum_luggage' => $this->maximum_luggage,
            'child_seat_required_rule' => $this->child_seat_required_rule,
            'special_assistance_supported' => (bool) $this->special_assistance_supported,
            'pricing_mode' => $this->pricing_mode,
            'base_price' => $this->base_price,
            'free_cancellation' => (bool) $this->free_cancellation,
            'cancellation_policy_type' => $this->cancellation_policy_type,
            'cancellation_deadline_at' => $this->cancellation_deadline_at?->toIso8601String(),
            'availability_status' => $this->availability_status,
            'bookable' => (bool) $this->bookable,
            'is_package_eligible' => (bool) $this->is_package_eligible,
            'status' => $this->status,
        ];
    }

    private function formatTimeForEmbed(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return (string) $value;
    }
}
