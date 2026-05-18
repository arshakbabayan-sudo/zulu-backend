<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.2 — blocked date range for an inventory item.
 */
class BlockedDate extends Model
{
    use HasFactory;

    public const ITEM_TYPES = ['hotel', 'flight', 'car', 'transfer', 'excursion', 'visa', 'package', 'offer'];

    protected $table = 'blocked_dates';

    protected $fillable = [
        'company_id',
        'item_type',
        'item_id',
        'blocked_from',
        'blocked_to',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'blocked_from' => 'date',
            'blocked_to' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
