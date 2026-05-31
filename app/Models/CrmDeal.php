<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A CRM sales opportunity. See migration
 * 2026_06_01_000010_create_crm_deals_table.php.
 */
class CrmDeal extends Model
{
    use SoftDeletes;

    protected $table = 'crm_deals';

    public const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    protected $fillable = [
        'company_id',
        'customer_user_id',
        'owner_user_id',
        'title',
        'value_amount',
        'currency',
        'service_type',
        'stage',
        'probability',
        'source',
        'expected_close_date',
        'notes',
    ];

    protected $casts = [
        'value_amount' => 'decimal:2',
        'probability' => 'integer',
        'expected_close_date' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
