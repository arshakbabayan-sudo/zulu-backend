<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.15 — payroll record (one row per employee per pay period).
 */
class PayrollRecord extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'finalized', 'paid'];

    protected $table = 'payroll_records';

    protected $fillable = [
        'company_id',
        'user_id',
        'period_start',
        'period_end',
        'base_salary',
        'hours_worked',
        'hourly_rate',
        'commission_amount',
        'bonus_amount',
        'deductions_amount',
        'gross_pay',
        'net_pay',
        'currency',
        'status',
        'paid_at',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'base_salary' => 'decimal:2',
            'hours_worked' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'bonus_amount' => 'decimal:2',
            'deductions_amount' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
