<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bonus extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_REDEEMED = 'redeemed';

    protected $fillable = [
        'company_id',
        'booking_id',
        'invoice_id',
        'amount',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
