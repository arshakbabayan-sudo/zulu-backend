<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const STATUSES = ['pending', 'in_review', 'ready_to_settle', 'settled', 'cancelled'];

    protected $table = 'settlements';

    protected $fillable = [
        'company_id',
        'currency',
        'total_gross_amount',
        'total_commission_amount',
        'total_net_amount',
        'entitlements_count',
        'status',
        'period_label',
        'settled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_gross_amount' => 'decimal:2',
            'total_commission_amount' => 'decimal:2',
            'total_net_amount' => 'decimal:2',
            'entitlements_count' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SupplierEntitlement::class);
    }
}
