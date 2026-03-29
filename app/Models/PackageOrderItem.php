<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageOrderItem extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const STATUSES = [
        'pending',
        'awaiting_supplier',
        'confirmed',
        'failed',
        'cancelled',
        'completed',
    ];

    protected $fillable = [
        'package_order_id',
        'package_component_id',
        'offer_id',
        'module_type',
        'package_role',
        'company_id',
        'is_required',
        'price_snapshot',
        'currency_snapshot',
        'status',
        'failure_reason',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'price_snapshot' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function packageOrder(): BelongsTo
    {
        return $this->belongsTo(PackageOrder::class);
    }

    public function packageComponent(): BelongsTo
    {
        return $this->belongsTo(PackageComponent::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
