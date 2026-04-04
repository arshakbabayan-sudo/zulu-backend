<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Excursion extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'location',
        'duration',
        'group_size',
        'country',
        'city',
        'general_category',
        'category',
        'excursion_type',
        'tour_name',
        'overview',
        'starts_at',
        'ends_at',
        'language',
        'ticket_max_count',
        'status',
        'is_available',
        'is_bookable',
        'includes',
        'meeting_pickup',
        'additional_info',
        'cancellation_policy',
        'photos',
        'price_by_dates',
        'visibility_rule',
        'appears_in_web',
        'appears_in_admin',
        'appears_in_zulu_admin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ticket_max_count' => 'integer',
            'is_available' => 'boolean',
            'is_bookable' => 'boolean',
            'includes' => 'array',
            'photos' => 'array',
            'price_by_dates' => 'array',
            'appears_in_web' => 'boolean',
            'appears_in_admin' => 'boolean',
            'appears_in_zulu_admin' => 'boolean',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
