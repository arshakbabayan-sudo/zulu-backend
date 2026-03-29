<?php

namespace App\Models;

use App\Traits\HasTranslations;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Offer extends Model
{
    use HasFactory, HasTranslations;

    /**
     * Allowed offer types (catalog, roadmap Phase 0, admin create).
     *
     * @var list<string>
     */
    public const ALLOWED_TYPES = [
        'flight',
        'hotel',
        'transfer',
        'car',
        'excursion',
        'package',
        'visa',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'company_id',
        'type',
        'title',
        'price',
        'currency',
        'status',
    ];

    protected $appends = ['b2b_price', 'b2c_price'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bookingItems(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function flight(): HasOne
    {
        return $this->hasOne(Flight::class);
    }

    public function hotel(): HasOne
    {
        return $this->hasOne(Hotel::class);
    }

    public function transfer(): HasOne
    {
        return $this->hasOne(Transfer::class);
    }

    public function car(): HasOne
    {
        return $this->hasOne(Car::class);
    }

    public function excursion(): HasOne
    {
        return $this->hasOne(Excursion::class);
    }

    public function package(): HasOne
    {
        return $this->hasOne(Package::class);
    }

    public function visa(): HasOne
    {
        return $this->hasOne(Visa::class);
    }

    /**
     * Get the B2B price (Net price).
     */
    public function getB2bPriceAttribute(): float
    {
        return (float) $this->price;
    }

    /**
     * Get the B2C price (retail = B2B + platform markup).
     */
    public function getB2cPriceAttribute(): float
    {
        return app(PriceCalculatorService::class)->b2cPrice($this->price ?? 0);
    }

    public function getTranslatableEntityType(): string
    {
        return 'offer';
    }
}
