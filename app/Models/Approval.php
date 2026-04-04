<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const ENTITY_TYPES = ['company', 'company_application', 'seller_request', 'package', 'offer'];

    /** @var list<string> */
    public const STATUSES = ['pending', 'under_review', 'approved', 'rejected'];

    protected $fillable = [
        'entity_type',
        'entity_id',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'reviewed_at',
        'reviewed_by',
        'priority',
        'notes',
        'decision_notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
