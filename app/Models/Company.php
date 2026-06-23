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
        // Phase 7.2 — admin archive (soft-delete-style, custom column)
        'archived_at',
        'archived_by_user_id',
        'archived_reason',
        // Phase Է — per-tenant Google Drive integration
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'google_drive_folder_id',
        'google_drive_connected_at',
        'google_drive_connected_email',
        // P0-1 step 1.1 — per-tenant Stripe Connect (Express) account.
        'stripe_connect_id',
        'stripe_charges_enabled',
        'stripe_payouts_enabled',
        'stripe_details_submitted',
        // §10 — set on the company's FIRST ZULU-approved package; thereafter
        // the company may self-publish packages directly.
        'packages_trusted_at',
        // Per-operator price markup (super-admin set). NULL → global default.
        'agent_markup_percent',
        'customer_markup_percent',
    ];

    /**
     * Token columns are NEVER serialized to API responses — even an admin
     * fetching a company row must not see another tenant's bearer.
     *
     * @var list<string>
     */
    protected $hidden = [
        'google_access_token',
        'google_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'is_seller' => 'boolean',
            'is_airline' => 'boolean',
            'is_partner_visible' => 'boolean',
            'profile_completed' => 'boolean',
            'seller_activated_at' => 'datetime',
            'archived_at' => 'datetime',
            'packages_trusted_at' => 'datetime',
            // Phase Է — token columns encrypted at rest. DB dumps + replica
            // exports won't leak usable Google bearers.
            'google_access_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'integer',
            'google_drive_connected_at' => 'datetime',
            // P0-1 step 1.1 — Stripe Connect onboarding state mirrored from Stripe.
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            // Per-operator markup tiers — kept as 2-decimal strings so a
            // NULL stays NULL (no per-operator value) and a set value rounds
            // to the AMD-consistent 2dp the markup math expects.
            'agent_markup_percent' => 'decimal:2',
            'customer_markup_percent' => 'decimal:2',
        ];
    }

    /**
     * True if this company has a Stripe Connect Express account that can
     * receive transfers — both charges and payouts are unlocked. Used by
     * the split-payment service (step 1.2) to gate "can we route money to
     * this seller yet".
     */
    public function hasStripeConnectReady(): bool
    {
        return $this->stripe_connect_id !== null
            && $this->stripe_charges_enabled === true
            && $this->stripe_payouts_enabled === true;
    }

    /**
     * True if this company has finished the Drive OAuth flow and has a
     * root folder ID we can write into. Used by FileAssetController to
     * gate uploads (per the user decision 2026-05-25: block upload until
     * Drive is connected, do not fall back to local disk).
     */
    public function hasGoogleDriveConnected(): bool
    {
        // Read the RAW (still-encrypted) refresh token rather than the decrypted
        // accessor: a token left over from a previous APP_KEY / a partial connect
        // can no longer be decrypted, and touching the `encrypted` cast would
        // throw a DecryptException — which used to 500 the status endpoint and
        // the upload gate. Presence of the stored value is all we need here; the
        // actual token is only decrypted when a Drive client is built.
        $rawRefresh = $this->getRawOriginal('google_refresh_token');

        return $rawRefresh !== null
            && $rawRefresh !== ''
            && $this->google_drive_folder_id !== null
            && $this->google_drive_folder_id !== '';
    }

    /** Active (non-archived) companies — used in default admin listings. */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /** Archived companies — used when admin opts in to see them. */
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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
