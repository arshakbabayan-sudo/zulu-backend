<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    use HasFactory, HasTranslations;

    /** @var list<string> */
    public const SERVICE_TYPES = ['scheduled', 'charter', 'package_flight', 'private_special'];

    /** @var list<string> */
    public const CONNECTION_TYPES = ['direct', 'connected'];

    /** @var list<string> */
    public const CABIN_CLASSES = ['economy', 'premium_economy', 'business', 'first'];

    /** @var list<string> */
    public const CANCELLATION_POLICY_TYPES = ['non_refundable', 'partially_refundable', 'fully_refundable'];

    /** @var list<string> */
    public const CHANGE_POLICY_TYPES = ['not_allowed', 'paid_change', 'free_change'];

    /** @var list<string> */
    public const STATUSES = ['draft', 'active', 'inactive', 'sold_out', 'cancelled', 'completed', 'archived'];

    protected $fillable = [
        'offer_id',
        'company_id',
        'visibility_rule',
        'appears_in_web',
        'appears_in_admin',
        'appears_in_zulu_admin',
        'flight_code_internal',
        'service_type',
        'departure_country',
        'departure_city',
        'departure_airport',
        'arrival_country',
        'arrival_city',
        'arrival_airport',
        'departure_airport_code',
        'arrival_airport_code',
        'departure_terminal',
        'arrival_terminal',
        'departure_at',
        'arrival_at',
        'duration_minutes',
        'timezone_context',
        'check_in_close_at',
        'boarding_close_at',
        'connection_type',
        'stops_count',
        'connection_notes',
        'layover_summary',
        'cabin_class',
        'seat_capacity_total',
        'seat_capacity_available',
        'fare_family',
        'seat_map_available',
        'seat_selection_policy',
        'adult_age_from',
        'child_age_from',
        'child_age_to',
        'infant_age_from',
        'infant_age_to',
        'adult_price',
        'child_price',
        'infant_price',
        'hand_baggage_included',
        'checked_baggage_included',
        'hand_baggage_weight',
        'checked_baggage_weight',
        'extra_baggage_allowed',
        'baggage_notes',
        'reservation_allowed',
        'online_checkin_allowed',
        'airport_checkin_allowed',
        'cancellation_policy_type',
        'change_policy_type',
        'reservation_deadline_at',
        'cancellation_deadline_at',
        'change_deadline_at',
        'policy_notes',
        'is_package_eligible',
        'appears_in_packages',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
            'check_in_close_at' => 'datetime',
            'boarding_close_at' => 'datetime',
            'reservation_deadline_at' => 'datetime',
            'cancellation_deadline_at' => 'datetime',
            'change_deadline_at' => 'datetime',
            'duration_minutes' => 'integer',
            'stops_count' => 'integer',
            'seat_capacity_total' => 'integer',
            'seat_capacity_available' => 'integer',
            'adult_age_from' => 'integer',
            'child_age_from' => 'integer',
            'child_age_to' => 'integer',
            'infant_age_from' => 'integer',
            'infant_age_to' => 'integer',
            'adult_price' => 'decimal:2',
            'child_price' => 'decimal:2',
            'infant_price' => 'decimal:2',
            'seat_map_available' => 'boolean',
            'appears_in_web' => 'boolean',
            'appears_in_admin' => 'boolean',
            'appears_in_zulu_admin' => 'boolean',
            'hand_baggage_included' => 'boolean',
            'checked_baggage_included' => 'boolean',
            'extra_baggage_allowed' => 'boolean',
            'reservation_allowed' => 'boolean',
            'online_checkin_allowed' => 'boolean',
            'airport_checkin_allowed' => 'boolean',
            'is_package_eligible' => 'boolean',
            'appears_in_packages' => 'boolean',
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

    public function cabins(): HasMany
    {
        return $this->hasMany(FlightCabin::class)->orderBy('id');
    }

    /**
     * Cabin rows for API payloads (B2C adult price per cabin).
     *
     * @return list<array<string, mixed>>
     */
    public function cabinsForApiResponse(): array
    {
        if (! $this->relationLoaded('cabins')) {
            return [];
        }

        return $this->cabins->map(fn (FlightCabin $c) => $c->toApiArray())->values()->all();
    }

    /**
     * Shape embedded under offer detail resources (operator + public catalog).
     *
     * @return array<string, mixed>
     */
    public function toOfferEmbedArray(): array
    {
        return [
            'flight_code_internal' => $this->flight_code_internal,
            'appears_in_web' => $this->appears_in_web,
            'appears_in_admin' => $this->appears_in_admin,
            'appears_in_zulu_admin' => $this->appears_in_zulu_admin,
            'service_type' => $this->service_type,
            'company_id' => $this->company_id,
            'departure_country' => $this->departure_country,
            'departure_city' => $this->departure_city,
            'departure_airport' => $this->departure_airport,
            'arrival_country' => $this->arrival_country,
            'arrival_city' => $this->arrival_city,
            'arrival_airport' => $this->arrival_airport,
            'departure_airport_code' => $this->departure_airport_code,
            'arrival_airport_code' => $this->arrival_airport_code,
            'departure_terminal' => $this->departure_terminal,
            'arrival_terminal' => $this->arrival_terminal,
            'departure_at' => $this->departure_at?->toIso8601String(),
            'arrival_at' => $this->arrival_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'timezone_context' => $this->timezone_context,
            'check_in_close_at' => $this->check_in_close_at?->toIso8601String(),
            'boarding_close_at' => $this->boarding_close_at?->toIso8601String(),
            'connection_type' => $this->connection_type,
            'stops_count' => $this->stops_count,
            'connection_notes' => $this->connection_notes,
            'layover_summary' => $this->layover_summary,
            'cabin_class' => $this->cabin_class,
            'seat_capacity_total' => $this->seat_capacity_total,
            'seat_capacity_available' => $this->seat_capacity_available,
            'fare_family' => $this->fare_family,
            'seat_map_available' => $this->seat_map_available,
            'seat_selection_policy' => $this->seat_selection_policy,
            'adult_age_from' => $this->adult_age_from,
            'child_age_from' => $this->child_age_from,
            'child_age_to' => $this->child_age_to,
            'infant_age_from' => $this->infant_age_from,
            'infant_age_to' => $this->infant_age_to,
            'adult_price' => $this->adult_price,
            'child_price' => $this->child_price,
            'infant_price' => $this->infant_price,
            'hand_baggage_included' => $this->hand_baggage_included,
            'checked_baggage_included' => $this->checked_baggage_included,
            'hand_baggage_weight' => $this->hand_baggage_weight,
            'checked_baggage_weight' => $this->checked_baggage_weight,
            'extra_baggage_allowed' => $this->extra_baggage_allowed,
            'baggage_notes' => $this->baggage_notes,
            'reservation_allowed' => $this->reservation_allowed,
            'online_checkin_allowed' => $this->online_checkin_allowed,
            'airport_checkin_allowed' => $this->airport_checkin_allowed,
            'cancellation_policy_type' => $this->cancellation_policy_type,
            'change_policy_type' => $this->change_policy_type,
            'reservation_deadline_at' => $this->reservation_deadline_at?->toIso8601String(),
            'cancellation_deadline_at' => $this->cancellation_deadline_at?->toIso8601String(),
            'change_deadline_at' => $this->change_deadline_at?->toIso8601String(),
            'policy_notes' => $this->policy_notes,
            'is_package_eligible' => $this->is_package_eligible,
            'status' => $this->status,
        ];
    }
}
