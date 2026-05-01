<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierEntitlement extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const STATUSES = ['pending', 'accrued', 'payable', 'settled', 'cancelled'];

    protected $table = 'supplier_entitlements';

    protected $fillable = [
        'package_order_id',
        'package_order_item_id',
        'booking_id',
        'booking_item_id',
        'company_id',
        'service_type',
        'gross_amount',
        'commission_amount',
        'net_amount',
        'currency',
        'status',
        'settlement_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
