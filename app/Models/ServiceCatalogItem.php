<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.12 — generic bookable service that doesn't fit standard inventory.
 */
class ServiceCatalogItem extends Model
{
    use HasFactory;

    public const UNITS = ['per_person', 'per_group', 'flat', 'per_hour', 'per_day'];

    protected $table = 'service_catalog_items';

    protected $fillable = [
        'company_id',
        'name',
        'category',
        'description',
        'base_price',
        'currency',
        'unit',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
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
