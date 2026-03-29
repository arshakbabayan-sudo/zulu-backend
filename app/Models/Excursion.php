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
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
