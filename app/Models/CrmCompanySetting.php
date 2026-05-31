<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company CRM settings. See migration
 * 2026_06_01_000050_create_crm_company_settings_table.php.
 *
 * `settings` is a free-form JSON blob; helpers below give typed access to the
 * known keys with safe defaults so a missing/partial row never breaks callers.
 */
class CrmCompanySetting extends Model
{
    protected $table = 'crm_company_settings';

    /** Order statuses eligible to be counted as a "sale". */
    public const SALES_STATUS_OPTIONS = ['pending_payment', 'paid', 'confirmed', 'completed'];

    /** Default counted statuses when a company hasn't customised. */
    public const DEFAULT_SALES_STATUSES = ['paid', 'confirmed'];

    protected $fillable = [
        'company_id',
        'settings',
        'updated_by_user_id',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Which order statuses count as a sale for this company. Falls back to the
     * default, and filters to the known option list so a stale value can't
     * widen the query unexpectedly.
     */
    public function salesCountStatuses(): array
    {
        $raw = $this->settings['sales_count_statuses'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return self::DEFAULT_SALES_STATUSES;
        }
        $clean = array_values(array_intersect($raw, self::SALES_STATUS_OPTIONS));
        return $clean !== [] ? $clean : self::DEFAULT_SALES_STATUSES;
    }
}
