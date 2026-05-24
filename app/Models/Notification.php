<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /** @var list<string> */
    public const EVENT_TYPES = [
        'package_order.created',
        'package_order.paid',
        'package_order.confirmed',
        'package_order.partially_confirmed',
        'package_order.partially_failed',
        'package_order.cancelled',
        'order.confirmed',
        'order.cancelled',
        'order.paid',
        'order.fulfilled',
        'payment.succeeded',
        'payment.failed',
        'voucher.issued',
        'voucher.voided',
        'voucher.reissued',
        'account.welcome',
        'account.password_reset',
        'offer.approved',
        'offer.rejected',
    ];

    /** @var list<string> */
    public const STATUSES = ['unread', 'read'];

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'status',
        'event_type',
        'subject_type',
        'subject_id',
        'related_company_id',
        'priority',
        'delivered_channels',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'delivered_channels' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
