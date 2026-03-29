<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'company_id',
        'status',
        'total_price',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
    /**
     * @return BelongsToMany<Passenger, $this>
     */
    public function passengers(): BelongsToMany
    {
        return $this->belongsToMany(Passenger::class, 'booking_passengers')
            ->withPivot('booking_item_id', 'seat_number', 'special_requests')
            ->withTimestamps();
    }

    public function scopeByLocation(Builder $query, mixed $cityId): Builder
    {
        if ($cityId === null || $cityId === '') {
            return $query;
        }

        $city = (string) $cityId;

        return $query->whereHas('items.offer.hotel', function (Builder $hotelQuery) use ($city): void {
            $hotelQuery->where('city', 'like', '%'.addcslashes($city, '%_\\').'%');
        });
    }

    public function scopeByDateRange(Builder $query, mixed $from, mixed $to): Builder
    {
        if (($from === null || $from === '') && ($to === null || $to === '')) {
            return $query;
        }

        return $query->whereHas('invoices', function (Builder $invoiceQuery) use ($from, $to): void {
            if ($from !== null && $from !== '') {
                $invoiceQuery->whereDate('check_in', '>=', $from);
            }
            if ($to !== null && $to !== '') {
                $invoiceQuery->whereDate('check_out', '<=', $to);
            }
        });
    }

    public function scopeByPrice(Builder $query, mixed $min, mixed $max): Builder
    {
        if (($min === null || $min === '') && ($max === null || $max === '')) {
            return $query;
        }

        return $query
            ->when($min !== null && $min !== '', fn (Builder $q) => $q->where('total_price', '>=', (float) $min))
            ->when($max !== null && $max !== '', fn (Builder $q) => $q->where('total_price', '<=', (float) $max));
    }

    public function scopeByStatus(Builder $query, mixed $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('status', (string) $status);
    }
}
