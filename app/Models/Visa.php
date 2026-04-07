<?php

namespace App\Models;

use App\Traits\HasTranslations;
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
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(VisaApplication::class);
    }
}
