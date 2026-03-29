<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory, HasTranslations;

    /** @var list<string> */
    public const PACKAGE_TYPES = ['fixed', 'dynamic', 'semi_fixed'];

    /** @var list<string> */
    public const DISPLAY_PRICE_MODES = ['total', 'per_person', 'from_price'];

    /** @var list<string> */
    public const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    protected $fillable = [
        'offer_id',
        'company_id',
        'package_type',
        'package_title',
        'package_subtitle',
        'destination_country',
        'destination_city',
        'duration_days',
        'min_nights',
        'adults_count',
        'children_count',
        'infants_count',
        'base_price',
        'display_price_mode',
        'currency',
        'is_public',
        'is_bookable',
        'is_package_eligible',
        'visibility_rule',
        'popularity_score',
        'is_featured',
        'component_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'min_nights' => 'integer',
            'adults_count' => 'integer',
            'children_count' => 'integer',
            'infants_count' => 'integer',
            'base_price' => 'decimal:2',
            'is_public' => 'boolean',
            'is_bookable' => 'boolean',
            'is_package_eligible' => 'boolean',
            'is_featured' => 'boolean',
            'popularity_score' => 'integer',
            'component_count' => 'integer',
        ];
    }

    /**
     * Back-compat for {@see OfferNormalizationService} (reads legacy `destination` column).
     */
    public function getDestinationAttribute(): ?string
    {
        $city = $this->attributes['destination_city'] ?? null;
        $country = $this->attributes['destination_country'] ?? null;
        if ($city !== null && $country !== null) {
            return $city.', '.$country;
        }

        return $city ?? $country;
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(PackageComponent::class)->orderBy('sort_order');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PackageOrder::class);
    }

    public function getTranslatableEntityType(): string
    {
        return 'package';
    }
}
