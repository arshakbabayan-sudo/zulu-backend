<?php

namespace App\Models;

use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightCabin extends Model
{
    /** @var list<string> */
    public const CABIN_CLASSES = ['economy', 'premium_economy', 'business', 'first'];

    protected $fillable = [
        'flight_id',
        'cabin_class',
        'seat_capacity_total',
        'seat_capacity_available',
        'adult_price',
        'child_price',
        'infant_price',
        'hand_baggage_included',
        'hand_baggage_weight',
        'checked_baggage_included',
        'checked_baggage_weight',
        'extra_baggage_allowed',
        'baggage_notes',
        'fare_family',
        'seat_map_available',
        'seat_selection_policy',
    ];

    protected function casts(): array
    {
        return [
            'adult_price' => 'decimal:2',
            'child_price' => 'decimal:2',
            'infant_price' => 'decimal:2',
            'hand_baggage_included' => 'boolean',
            'checked_baggage_included' => 'boolean',
            'extra_baggage_allowed' => 'boolean',
            'seat_map_available' => 'boolean',
            'seat_capacity_total' => 'integer',
            'seat_capacity_available' => 'integer',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'cabin_class' => $this->cabin_class,
            'seat_capacity_total' => $this->seat_capacity_total,
            'seat_capacity_available' => $this->seat_capacity_available,
            'adult_price' => (float) $this->adult_price,
            'child_price' => (float) $this->child_price,
            'infant_price' => (float) $this->infant_price,
            'hand_baggage_included' => $this->hand_baggage_included,
            'hand_baggage_weight' => $this->hand_baggage_weight,
            'checked_baggage_included' => $this->checked_baggage_included,
            'checked_baggage_weight' => $this->checked_baggage_weight,
            'extra_baggage_allowed' => $this->extra_baggage_allowed,
            'baggage_notes' => $this->baggage_notes,
            'fare_family' => $this->fare_family,
            'seat_map_available' => $this->seat_map_available,
            'b2c_adult_price' => app(PriceCalculatorService::class)->b2cPrice($this->adult_price),
        ];
    }
}
