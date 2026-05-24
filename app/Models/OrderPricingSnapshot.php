<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 1 / Step F — immutable per-order-item pricing snapshot.
 *
 * Written once at order creation time. Never updated. If a pricing_rule
 * or exchange_rate changes after the booking, the order keeps its
 * original numbers — critical for finance auditability.
 */
class OrderPricingSnapshot extends Model
{
    use HasFactory;

    protected $table = 'order_pricing_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'offer_id',
        'supplier_net',
        'zulu_markup_value',
        'zulu_markup_type',
        'agent_net',
        'customer_paid',
        'agent_margin',
        'rule_id_applied',
        'currency',
        'snapshot_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'supplier_net' => 'decimal:4',
            'zulu_markup_value' => 'decimal:4',
            'agent_net' => 'decimal:4',
            'customer_paid' => 'decimal:4',
            'agent_margin' => 'decimal:4',
            'snapshot_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class, 'rule_id_applied');
    }
}
