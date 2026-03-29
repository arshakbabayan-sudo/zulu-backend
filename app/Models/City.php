<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'region_id',
        'country_id',
        'name',
        'is_active',
        'sort_order',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'country_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}

