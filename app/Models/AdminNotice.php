<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-authored platform announcement (Settings → System notifications).
 * Sending materialises per-user Notification rows via SendBulkNotificationJob.
 */
class AdminNotice extends Model
{
    public const TYPES = ['maintenance', 'announcement', 'info'];

    public const AUDIENCES = ['everyone', 'all_b2c', 'all_staff', 'by_company'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'title',
        'message',
        'type',
        'audience',
        'company_id',
        'channels',
        'priority',
        'scheduled_for',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'channels' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Derived lifecycle state for the admin UI. */
    public function statusLabel(): string
    {
        if ($this->sent_at !== null) {
            return 'sent';
        }
        if (! $this->is_active) {
            return 'paused';
        }

        return $this->scheduled_for !== null ? 'scheduled' : 'draft';
    }
}
