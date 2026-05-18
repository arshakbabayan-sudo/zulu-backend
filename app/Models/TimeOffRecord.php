<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.13 — time-off / non-service-hours record for an employee.
 */
class TimeOffRecord extends Model
{
    use HasFactory;

    public const TYPES = ['vacation', 'sick', 'personal', 'unpaid', 'other'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $table = 'time_off_records';

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'starts_on',
        'ends_on',
        'hours_total',
        'notes',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'hours_total' => 'decimal:2',
            'decided_at' => 'datetime',
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

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
