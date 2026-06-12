<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFieldValues;
use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasCustomFieldValues, HasFactory, HasTranslations;

    public function customFieldScope(): string
    {
        return 'package';
    }

    /**
     * Fields auto-translated by the Claude AI translator on save.
     *
     * @var list<string>
     */
    protected array $translatableFields = [
        'package_title',
        'package_subtitle',
    ];

    /** @var list<string> */
    public const PACKAGE_TYPES = ['fixed', 'dynamic', 'semi_fixed'];

    /** @var list<string> */
    public const DISPLAY_PRICE_MODES = ['total', 'per_person', 'from_price'];

    /** @var list<string> */
    public const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    protected $fillable = [
        'source_lang',
        'offer_id',
        'company_id',
        'package_type',
        'package_title',
        'package_subtitle',
        'destination_country',
        'destination_city',
        'destination_location_id',
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
        'short_description',
        'main_image',
        'latitude',
        'longitude',
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

    /**
     * @return HasMany<PackageHomepageFeature, $this>
     */
    public function homepageFeatures(): HasMany
    {
        return $this->hasMany(PackageHomepageFeature::class);
    }

    public function getTranslatableEntityType(): string
    {
        return 'package';
    }

    /**
     * Filter packages whose destination location is in the subtree of the
     * given Location id (mirrors Hotel/Car/Excursion/Visa/Transfer scopes).
     */
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

        return $query->whereIn($query->getModel()->getTable().'.destination_location_id', $ids);
    }
}
