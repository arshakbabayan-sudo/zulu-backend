<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id',
        'room_type',
        'room_name',
        'max_adults',
        'max_children',
        'max_total_guests',
        'bed_type',
        'bed_count',
        'room_size',
        'room_view',
        'private_bathroom',
        'smoking_allowed',
        'air_conditioning',
        'wifi',
        'tv',
        'mini_fridge',
        'tea_coffee_maker',
        'kettle',
        'washing_machine',
        'soundproofing',
        'terrace_or_balcony',
        'patio',
        'bath',
        'shower',
        'view_type',
        'room_images',
        'room_inventory_count',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_bathroom' => 'boolean',
            'smoking_allowed' => 'boolean',
            'air_conditioning' => 'boolean',
            'wifi' => 'boolean',
            'tv' => 'boolean',
            'mini_fridge' => 'boolean',
            'tea_coffee_maker' => 'boolean',
            'kettle' => 'boolean',
            'washing_machine' => 'boolean',
            'soundproofing' => 'boolean',
            'terrace_or_balcony' => 'boolean',
            'patio' => 'boolean',
            'bath' => 'boolean',
            'shower' => 'boolean',
            'room_images' => 'array',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function pricings(): HasMany
    {
        return $this->hasMany(HotelRoomPricing::class, 'hotel_room_id');
    }
}
