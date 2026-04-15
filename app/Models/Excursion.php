<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Excursion extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'offer_id',
        'location',
        'duration',
        'group_size',
        'country',
        'city',
        'location_id',
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
            'location_id' => 'integer',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'excursion_location')
            ->withPivot(['is_primary', 'sort_order'])
            ->withTimestamps();
    }

    public function scopeForLocation(Builder $query, int|string|null $locationId): Builder
    {
        $id = is_numeric($locationId) ? (int) $locationId : 0;
        if ($id <= 0) {
            return $query;
        }

        $ids = Location::subtreeLocationIds($id);
        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($table, $ids): void {
            $q->whereIn($table.'.location_id', $ids)
                ->orWhereHas('locations', function (Builder $lq) use ($ids): void {
                    $lq->whereIn('locations.id', $ids);
                });
        });
    }
}
