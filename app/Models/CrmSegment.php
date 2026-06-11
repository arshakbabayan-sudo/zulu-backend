<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A CRM segment — a saved customer group for campaign targeting. See
 * migration 2026_06_12_000010_create_crm_leads_and_segments_tables.php.
 *
 * type=dynamic: membership is computed live from the rules json (evaluated
 * against the company's order-derived buyers — same population as CRM →
 * Customers). type=static: membership is the explicit crm_segment_members
 * list managed via the members endpoints.
 */
class CrmSegment extends Model
{
    use SoftDeletes;

    protected $table = 'crm_segments';

    public const TYPES = ['dynamic', 'static'];

    /** Recognised dynamic-rule keys (all optional, AND-combined). */
    public const RULE_KEYS = ['min_bookings', 'min_total_spent', 'inactive_months', 'active_months'];

    protected $fillable = [
        'company_id',
        'created_by_user_id',
        'name',
        'description',
        'type',
        'icon',
        'rules',
    ];

    protected $casts = [
        'rules' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'crm_segment_members', 'segment_id', 'user_id')->withTimestamps();
    }
}
