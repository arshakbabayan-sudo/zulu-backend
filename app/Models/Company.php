<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, HasTranslations;

    /**
     * Fields auto-translated by the Claude AI translator on save.
     * Note: company `name` intentionally NOT translated — brand names
     * stay in their original form across locales.
     *
     * @var list<string>
     */
    protected array $translatableFields = [
        'description',
        'address',
    ];

    /** @var list<string> */
    public const GOVERNANCE_STATUSES = ['pending', 'active', 'suspended', 'rejected'];

    /** @var list<string> */
    public const TYPES = ['operator', 'agency', 'airline', 'hotel_chain', 'other'];

    protected $fillable = [
        'source_lang',
        'name',
        'type',
        'status',
        'slug',
        'legal_name',
        'tax_id',
        'country',
        'city',
        'address',
        'phone',
        'website',
        'description',
        'logo',
        'is_partner_visible',
        'governance_status',
        'is_seller',
        'is_airline',
        'seller_activated_at',
        'profile_completed',
    ];

    protected function casts(): array
    {
        return [
            'is_seller' => 'boolean',
            'is_airline' => 'boolean',
            'is_partner_visible' => 'boolean',
            'profile_completed' => 'boolean',
            'seller_activated_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_company')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserCompany::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function sellerPermissions(): HasMany
    {
        return $this->hasMany(CompanySellerPermission::class);
    }

    /** Phase 6A — per-company admin module visibility rows. */
    public function modulePermissions(): HasMany
    {
        return $this->hasMany(CompanyModulePermission::class);
    }

    public function sellerApplications(): HasMany
    {
        return $this->hasMany(CompanySellerApplication::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SupplierEntitlement::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
