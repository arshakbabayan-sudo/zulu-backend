<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BookingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'offer_id',
        'price',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsToMany<Passenger, $this>
     */
    public function passengers(): BelongsToMany
    {
        return $this->belongsToMany(Passenger::class, 'booking_passengers')
            ->withPivot('seat_number', 'special_requests')
            ->withTimestamps();
    }
}
