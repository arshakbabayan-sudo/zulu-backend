<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'booking_id',
        'package_order_id',
        'unique_booking_reference',
        'total_amount',
        'currency',
        'status',
        'notes',
        'vendor_locator',
        'ticket_time_limit',
        'issuing_date',
        'net_price',
        'client_price',
        'commission_total',
        'refund_amount',
        'vat_amount',
        'cancellation_without_penalty',
        'payment_type',
        'additional_services_price',
        'invoice_type',
        'check_in',
        'check_out',
        'nights',
        'room_nights',
        'avg_daily_rate',
        'hotel_name',
        'hotel_line',
        'room_type',
        'rate_name',
        'hotel_order_id',
        'guest_names',
        'adults_count',
        'children_count',
        'meal_plan',
        'supplier_id',
        'order_source',
        'promo_code',
    ];

    protected function casts(): array
    {
        return [
            'ticket_time_limit' => 'datetime',
            'issuing_date' => 'date',
            'check_in' => 'date',
            'check_out' => 'date',
            'total_amount' => 'decimal:2',
            'net_price' => 'decimal:2',
            'client_price' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'additional_services_price' => 'decimal:2',
            'avg_daily_rate' => 'decimal:2',
            'nights' => 'integer',
            'room_nights' => 'integer',
            'adults_count' => 'integer',
            'children_count' => 'integer',
            'cancellation_without_penalty' => 'boolean',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function packageOrder(): BelongsTo
    {
        return $this->belongsTo(PackageOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeByLocation(Builder $query, mixed $cityId): Builder
    {
        if ($cityId === null || $cityId === '') {
            return $query;
        }

        $city = (string) $cityId;

        return $query->whereHas('booking.items.offer.hotel', function (Builder $hotelQuery) use ($city): void {
            $hotelQuery->where('city', 'like', '%'.addcslashes($city, '%_\\').'%');
        });
    }

    public function scopeByDateRange(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query
            ->when($from !== null && $from !== '', fn (Builder $q) => $q->whereDate('check_in', '>=', $from))
            ->when($to !== null && $to !== '', fn (Builder $q) => $q->whereDate('check_out', '<=', $to));
    }

    public function scopeByPrice(Builder $query, mixed $min, mixed $max): Builder
    {
        return $query
            ->when($min !== null && $min !== '', fn (Builder $q) => $q->where('total_amount', '>=', (float) $min))
            ->when($max !== null && $max !== '', fn (Builder $q) => $q->where('total_amount', '<=', (float) $max));
    }

    public function scopeByStatus(Builder $query, mixed $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('status', (string) $status);
    }
}
