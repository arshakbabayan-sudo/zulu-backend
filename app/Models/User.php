<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements CanResetPasswordContract, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'phone',
        'preferred_language',
        'avatar',
        'birth_date',
        'nationality',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_company')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function belongsToCompany(int $companyId): bool
    {
        return $this->companies()->whereKey($companyId)->exists();
    }

    public function hasCompanyPermission(int $companyId, string $permission): bool
    {
        $membership = $this->memberships()
            ->where('company_id', $companyId)
            ->first();

        if (! $membership || $membership->role_id === null) {
            return false;
        }

        return Role::query()
            ->whereKey($membership->role_id)
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserCompany::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function savedItems(): HasMany
    {
        return $this->hasMany(SavedItem::class);
    }

    public function packageOrders(): HasMany
    {
        return $this->hasMany(PackageOrder::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
