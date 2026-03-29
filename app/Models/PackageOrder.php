<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageOrder extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const BOOKING_CHANNELS = ['public_b2c', 'b2b_company', 'internal_admin'];

    /** @var list<string> */
    public const STATUSES = [
        'draft',
        'pending_payment',
        'paid',
        'partially_confirmed',
        'confirmed',
        'partially_failed',
        'cancelled',
        'completed',
    ];

    /** @var list<string> */
    public const PAYMENT_STATUSES = ['unpaid', 'pending', 'paid', 'refunded', 'partially_refunded'];

    /** @var list<string> */
    public const DISPLAY_PRICE_MODES = ['total', 'per_person', 'from_price'];

    protected $fillable = [
        'package_id',
        'user_id',
        'company_id',
        'order_number',
        'booking_channel',
        'status',
        'payment_status',
        'adults_count',
        'children_count',
        'infants_count',
        'currency',
        'base_component_total_snapshot',
        'discount_snapshot',
        'markup_snapshot',
        'addon_total_snapshot',
        'final_total_snapshot',
        'display_price_mode_snapshot',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'adults_count' => 'integer',
            'children_count' => 'integer',
            'infants_count' => 'integer',
            'base_component_total_snapshot' => 'decimal:2',
            'discount_snapshot' => 'decimal:2',
            'markup_snapshot' => 'decimal:2',
            'addon_total_snapshot' => 'decimal:2',
            'final_total_snapshot' => 'decimal:2',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageOrderItem::class)->orderBy('sort_order');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SupplierEntitlement::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'package_order_id');
    }
}
