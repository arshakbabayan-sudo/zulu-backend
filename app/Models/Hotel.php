<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotel extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'offer_id',
        'company_id',
        'visibility_rule',
        'hotel_name',
        'property_type',
        'hotel_type',
        'star_rating',
        'country',
        'region_or_state',
        'city',
        'district_or_area',
        'full_address',
        'latitude',
        'longitude',
        'short_description',
        'main_image',
        'check_in_time',
        'check_out_time',
        'meal_type',
        'free_wifi',
        'parking',
        'airport_shuttle',
        'indoor_pool',
        'outdoor_pool',
        'room_service',
        'front_desk_24h',
        'child_friendly',
        'accessibility_support',
        'pets_allowed',
        'free_cancellation',
        'cancellation_policy_type',
        'cancellation_deadline_at',
        'prepayment_required',
        'no_show_policy',
        'review_score',
        'review_count',
        'review_label',
        'availability_status',
        'bookable',
        'room_inventory_mode',
        'is_package_eligible',
        'appears_in_packages',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'free_wifi' => 'boolean',
            'parking' => 'boolean',
            'airport_shuttle' => 'boolean',
            'indoor_pool' => 'boolean',
            'outdoor_pool' => 'boolean',
            'room_service' => 'boolean',
            'front_desk_24h' => 'boolean',
            'child_friendly' => 'boolean',
            'accessibility_support' => 'boolean',
            'pets_allowed' => 'boolean',
            'free_cancellation' => 'boolean',
            'prepayment_required' => 'boolean',
            'bookable' => 'boolean',
            'is_package_eligible' => 'boolean',
            'appears_in_packages' => 'boolean',
            'cancellation_deadline_at' => 'datetime',
            'review_score' => 'decimal:2',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
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

    public function rooms(): HasMany
    {
        return $this->hasMany(HotelRoom::class);
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
            'hotel_name' => $this->hotel_name,
            'property_type' => $this->property_type,
            'hotel_type' => $this->hotel_type,
            'star_rating' => $this->star_rating,
            'country' => $this->country,
            'city' => $this->city,
            'district_or_area' => $this->district_or_area,
            'meal_type' => $this->meal_type,
            'availability_status' => $this->availability_status,
            'bookable' => $this->bookable,
            'status' => $this->status,
            'main_image' => $this->main_image,
        ];
    }

    public function getTranslatableEntityType(): string
    {
        return 'hotel';
    }
}
