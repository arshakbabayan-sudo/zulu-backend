<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A CRM lead — an inbound enquiry that is not yet a deal. See migration
 * 2026_06_12_000010_create_crm_leads_and_segments_tables.php.
 *
 * 'converted' is NOT a manually settable status — it is reached only via
 * POST crm/leads/{lead}/convert (which also creates the CrmDeal and stamps
 * converted_deal_id/converted_at).
 */
class CrmLead extends Model
{
    use SoftDeletes;

    protected $table = 'crm_leads';

    public const SOURCES = ['website', 'referral', 'social', 'walk_in', 'partner'];

    public const INTERESTS = ['hotel', 'flight', 'package', 'tour', 'transfer'];

    public const STATUSES = ['new', 'contacted', 'qualified', 'unqualified', 'converted'];

    /** Statuses a client may set directly (everything except 'converted'). */
    public const SETTABLE_STATUSES = ['new', 'contacted', 'qualified', 'unqualified'];

    protected $fillable = [
        'company_id',
        'owner_user_id',
        'name',
        'email',
        'phone',
        'b2b_company',
        'source',
        'interest',
        'status',
        'notes',
        'converted_deal_id',
        'converted_at',
    ];

    protected $casts = [
        'converted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'converted_deal_id');
    }
}
