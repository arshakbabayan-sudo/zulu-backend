<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageHomepageFeature extends Model
{
    use HasFactory;

    public const SECTION_SPECIAL_OFFERS = 'special_offers';

    public const SECTION_POPULAR_DESTINATIONS = 'popular_destinations';

    /** @var list<string> */
    public const SECTIONS = [
        self::SECTION_SPECIAL_OFFERS,
        self::SECTION_POPULAR_DESTINATIONS,
    ];

    protected $fillable = [
        'package_id',
        'section_slug',
        'position',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
