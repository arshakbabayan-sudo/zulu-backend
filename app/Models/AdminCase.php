<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.10 — case (advanced support ticket with assignment + priority).
 *
 * Class is named AdminCase to avoid clashing with PHP's reserved Case
 * keyword in some contexts; the table stays `cases`.
 */
class AdminCase extends Model
{
    use HasFactory;

    public const STATUSES = ['open', 'in_progress', 'pending_customer', 'resolved', 'closed', 'escalated'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $table = 'cases';

    protected $fillable = [
        'case_number',
        'company_id',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to_user_id',
        'opened_by_user_id',
        'opened_at',
        'closed_at',
        'closing_notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }
}
