<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelRoomPricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_room_id',
        'price',
        'currency',
        'pricing_mode',
        'valid_from',
        'valid_to',
        'min_nights',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }
}
