<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer-initiated refund request on one of their orders (account #12).
 */
class RefundRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'order_id',
        'reason',
        'amount_requested',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'amount_requested' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * §8 — resolve the paid Payment to refund for this request: the order's
     * direct payment if paid, else the newest paid payment across its invoices.
     * Returns null when nothing refundable is found.
     */
    public function refundablePayment(): ?Payment
    {
        $order = $this->order()->with(['payment', 'invoices.payments'])->first();
        if ($order === null) {
            return null;
        }

        if ($order->payment !== null && $order->payment->status === Payment::STATUS_PAID) {
            return $order->payment;
        }

        return $order->invoices
            ->flatMap(fn ($invoice) => $invoice->payments)
            ->filter(fn (Payment $p) => $p->status === Payment::STATUS_PAID)
            ->sortByDesc('id')
            ->first();
    }
}
