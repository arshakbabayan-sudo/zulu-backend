<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PENDING,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'user_id',
        'company_id',
        'subject',
        'status',
        'priority',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }
}
