<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Car extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const PRICING_MODES = [
        'per_day',
        'per_hour',
        'per_km',
        'fixed',
        'inherit_offer',
    ];

    /** @var list<string> */
    public const AVAILABILITY_STATUSES = [
        'available',
        'limited',
        'booked',
        'maintenance',
        'inactive',
    ];

    /** @var list<string> */
    public const OPERATIONAL_STATUSES = [
        'draft',
        'published',
        'archived',
        'suspended',
    ];

    protected $fillable = [
        'offer_id',
        'pickup_location',
        'dropoff_location',
        'vehicle_class',
        'vehicle_type',
        'brand',
        'model',
        'year',
        'transmission_type',
        'fuel_type',
        'fleet',
        'category',
        'seats',
        'suitcases',
        'small_bag',
        'availability_window_start',
        'availability_window_end',
        'pricing_mode',
        'base_price',
        'status',
        'availability_status',
        'advanced_options',
        'visibility_rule',
        'appears_in_web',
        'appears_in_admin',
        'appears_in_zulu_admin',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'seats' => 'integer',
            'suitcases' => 'integer',
            'small_bag' => 'integer',
            'availability_window_start' => 'datetime',
            'availability_window_end' => 'datetime',
            'base_price' => 'decimal:2',
            'advanced_options' => 'array',
            'appears_in_web' => 'boolean',
            'appears_in_admin' => 'boolean',
            'appears_in_zulu_admin' => 'boolean',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
