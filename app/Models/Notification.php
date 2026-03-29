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
        'payment.succeeded',
        'payment.failed',
        'account.welcome',
        'account.password_reset',
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
