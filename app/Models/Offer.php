<?php

namespace App\Models;

use App\Services\Pricing\PriceCalculatorService;
use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

class Offer extends Model
{
    use HasFactory, HasTranslations, Searchable;

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

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    /** All valid status values. */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'company_id',
        'type',
        'title',
        'price',
        'currency',
        'status',
        'submitted_for_review_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected $casts = [
        'submitted_for_review_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = ['b2b_price', 'b2c_price'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    /**
     * Meilisearch index name. Single index for every offer type — the
     * `type` field is a filterable attribute, so DiscoveryService can
     * scope a free-text query to one module_type with a filter clause.
     */
    public function searchableAs(): string
    {
        return 'zulu_offers';
    }

    /**
     * Only published offers go into the index. Drafts / archived /
     * pending_review never become searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Document shape sent to Meilisearch. We collapse every type-specific
     * field into a single `searchable_text` blob so the same query works
     * across hotels / flights / packages / etc., and keep the numeric +
     * categorical fields top-level so they remain filterable.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $textParts = [(string) ($this->title ?? '')];

        if ($this->type === 'hotel') {
            $hotel = $this->hotel;
            if ($hotel) {
                $textParts[] = (string) ($hotel->name ?? '');
                $textParts[] = (string) ($hotel->description ?? '');
                $textParts[] = (string) ($hotel->address ?? '');
                $textParts[] = (string) ($hotel->accommodation_type ?? '');
                $textParts[] = $this->locationLabelFor($hotel->location_id ?? null);
            }
        } elseif ($this->type === 'flight') {
            $flight = $this->flight;
            if ($flight) {
                $textParts[] = (string) ($flight->flight_code_internal ?? '');
                $textParts[] = (string) ($flight->airline ?? '');
                $textParts[] = (string) ($flight->departure_airport_code ?? '');
                $textParts[] = (string) ($flight->arrival_airport_code ?? '');
                $textParts[] = $this->locationLabelFor($flight->departure_location_id ?? null);
                $textParts[] = $this->locationLabelFor($flight->arrival_location_id ?? null);
            }
        } elseif ($this->type === 'package') {
            $package = $this->package;
            if ($package) {
                $textParts[] = (string) ($package->title ?? '');
                $textParts[] = (string) ($package->description ?? '');
                $textParts[] = $this->locationLabelFor($package->location_id ?? null);
            }
        } elseif ($this->type === 'car') {
            $car = $this->car;
            if ($car) {
                $textParts[] = (string) ($car->brand ?? '');
                $textParts[] = (string) ($car->model ?? '');
                $textParts[] = (string) ($car->vehicle_class ?? '');
                $textParts[] = $this->locationLabelFor($car->location_id ?? null);
            }
        } elseif ($this->type === 'excursion') {
            $excursion = $this->excursion;
            if ($excursion) {
                $textParts[] = (string) ($excursion->title ?? '');
                $textParts[] = (string) ($excursion->description ?? '');
                $textParts[] = $this->locationLabelFor($excursion->location_id ?? null);
            }
        } elseif ($this->type === 'transfer') {
            $transfer = $this->transfer;
            if ($transfer) {
                $textParts[] = (string) ($transfer->vehicle_class ?? '');
                $textParts[] = $this->locationLabelFor($transfer->from_location_id ?? null);
                $textParts[] = $this->locationLabelFor($transfer->to_location_id ?? null);
            }
        } elseif ($this->type === 'visa') {
            $visa = $this->visa;
            if ($visa) {
                $textParts[] = (string) ($visa->country ?? '');
                $textParts[] = (string) ($visa->visa_type ?? '');
                $textParts[] = $this->locationLabelFor($visa->location_id ?? null);
            }
        }

        return [
            'id' => (int) $this->id,
            'type' => (string) $this->type,
            'title' => (string) ($this->title ?? ''),
            'price' => (float) ($this->price ?? 0),
            'currency' => (string) ($this->currency ?? 'USD'),
            'company_id' => $this->company_id !== null ? (int) $this->company_id : null,
            'status' => (string) $this->status,
            'searchable_text' => trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter($textParts)))),
        ];
    }

    private function locationLabelFor(?int $locationId): string
    {
        if (! $locationId) {
            return '';
        }
        // ZULU locations are a name + parent_id tree (city → region →
        // country), not flat city/region/country columns. Walk up to 4
        // hops so a hotel pinned to "Sharm El Sheikh" surfaces for both
        // city and country queries ("Egypt").
        $parts = [];
        $current = Location::query()->find($locationId);
        $hops = 0;
        while ($current && $hops < 4) {
            $name = (string) ($current->name ?? '');
            if ($name !== '') {
                $parts[] = $name;
            }
            $parentId = $current->parent_id ?? null;
            if (! $parentId) {
                break;
            }
            $current = Location::query()->find($parentId);
            $hops++;
        }

        return implode(' ', $parts);
    }
}
