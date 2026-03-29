<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRecord extends Model
{
    use HasFactory;

    public const SUBJECT_TYPES = ['package_order', 'booking'];

    public const STATUSES = ['pending', 'accrued', 'settled', 'cancelled'];

    protected $fillable = [
        'commission_policy_id',
        'subject_type',
        'subject_id',
        'company_id',
        'service_type',
        'commission_mode',
        'commission_value',
        'commission_amount_snapshot',
        'currency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'commission_value' => 'decimal:4',
            'commission_amount_snapshot' => 'decimal:2',
        ];
    }

    public function commissionPolicy(): BelongsTo
    {
        return $this->belongsTo(CommissionPolicy::class, 'commission_policy_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
