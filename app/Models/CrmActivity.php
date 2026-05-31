<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CRM interaction log entry (call/email/meeting/task/note). See migration
 * 2026_06_01_000020_create_crm_activities_table.php.
 */
class CrmActivity extends Model
{
    protected $table = 'crm_activities';

    public const TYPES = ['call', 'email', 'meeting', 'task', 'note'];
    public const STATUSES = ['open', 'done', 'overdue'];
    public const SUBJECT_TYPES = ['customer', 'deal', 'lead'];

    protected $fillable = [
        'company_id',
        'owner_user_id',
        'type',
        'subject',
        'body',
        'subject_type',
        'subject_id',
        'status',
        'due_at',
        'completed_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
