<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InsuranceProduct extends Model
{
    use SoftDeletes;

    protected $table = 'insurance_products';

    public const STATUSES = ['active', 'inactive', 'archived'];

    public const TERRITORIES = ['schengen', 'europe', 'worldwide', 'custom'];

    protected $fillable = [
        'company_id',
        'underwriter_name',
        'underwriter_license_ref',
        'product_name',
        'coverage_territory',
        'covered_countries',
        'excluded_countries',
        'coverage_details',
        'age_tiers',
        'base_premium',
        'currency',
        'duration_options',
        'pre_existing_covered',
        'sports_coverage',
        'exclusions',
        'policy_template_url',
        'status',
    ];

    protected $casts = [
        'covered_countries' => 'array',
        'excluded_countries' => 'array',
        'coverage_details' => 'array',
        'age_tiers' => 'array',
        'duration_options' => 'array',
        'sports_coverage' => 'array',
        'exclusions' => 'array',
        'base_premium' => 'decimal:2',
        'pre_existing_covered' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class, 'product_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
