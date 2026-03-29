<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Passenger extends Model
{
    use HasFactory;

    public const TYPES = ['adult', 'child', 'infant'];

    public const GENDERS = ['male', 'female', 'other'];

    protected $fillable = [
        'first_name',
        'last_name',
        'passport_number',
        'passport_expiry',
        'nationality',
        'date_of_birth',
        'gender',
        'passenger_type',
        'email',
        'phone',
    ];

    protected function casts(): array
    {
        return [
            'passport_expiry' => 'date',
            'date_of_birth' => 'date',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * @return BelongsToMany<Booking, $this>
     */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_passengers')
            ->withPivot('seat_number', 'special_requests')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<BookingItem, $this>
     */
    public function bookingItems(): BelongsToMany
    {
        return $this->belongsToMany(BookingItem::class, 'booking_passengers')
            ->withPivot('seat_number', 'special_requests')
            ->withTimestamps();
    }
}
