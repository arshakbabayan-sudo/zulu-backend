<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'orders';

    protected $keyType = 'string';

    public $incrementing = false;

    public const BUYER_TYPES = ['client', 'seller_b2b', 'guest'];

    /** @var list<string> */
    public const BOOKING_CHANNELS = ['public_b2c', 'b2b_company', 'internal_admin'];

    public const STATUSES = [
        'cart',
        'pending_payment',
        'paid',
        'confirmed',
        'cancelled',
        'refunded',
        'failed',
    ];

    protected $fillable = [
        'order_number',
        'user_id',
        'company_id',
        'agent_company_id',
        'buyer_type',
        'status',
        'currency',
        'subtotal',
        'tax',
        'total',
        'payment_id',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function agentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agent_company_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'order_id');
    }
}
