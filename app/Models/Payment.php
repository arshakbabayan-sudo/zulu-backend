<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'invoice_id',
        'amount',
        'refunded_amount',
        'currency',
        'status',
        'payment_method',
        'reference_code',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'refunded_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
