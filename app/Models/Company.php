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

    /** @var list<string> */
    public const GOVERNANCE_STATUSES = ['pending', 'active', 'suspended', 'rejected'];

    /** @var list<string> */
    public const TYPES = ['operator', 'agency', 'airline', 'hotel_chain', 'other'];

    protected $fillable = [
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

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionPolicy::class);
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
