<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Car extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'pickup_location',
        'dropoff_location',
        'vehicle_class',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
