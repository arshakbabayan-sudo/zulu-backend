<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visa extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'offer_id',
        'country_id',
        'location_id',
        'country',
        'name',
        'price',
        'description',
        'required_documents',
        'visa_type',
        'processing_days',
    ];

    protected $casts = [
        'required_documents' => 'array',
        'price' => 'decimal:2',
        'location_id' => 'integer',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(VisaApplication::class);
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

        return $query->whereIn($query->getModel()->getTable().'.location_id', $ids);
    }
}
