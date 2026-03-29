<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    /** @var list<string> */
    public const TARGET_ENTITY_TYPES = ['hotel', 'package', 'flight', 'transfer', 'excursion', 'car'];

    /** @var list<string> */
    public const STATUSES = ['pending', 'published', 'hidden', 'rejected'];

    protected $fillable = [
        'user_id',
        'package_order_id',
        'booking_id',
        'target_entity_type',
        'target_entity_id',
        'rating',
        'review_text',
        'status',
        'moderation_notes',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function packageOrder(): BelongsTo
    {
        return $this->belongsTo(PackageOrder::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
