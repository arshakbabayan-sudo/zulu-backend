<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageComponent extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const MODULE_TYPES = ['flight', 'hotel', 'transfer', 'excursion', 'car', 'visa', 'addon'];

    /** @var list<string> */
    public const PACKAGE_ROLES = ['flight', 'stay', 'transfer', 'excursion', 'car', 'visa', 'addon'];

    /** @var list<string> */
    public const SELECTION_MODES = ['fixed', 'optional', 'alternative', 'system_selected'];

    protected $fillable = [
        'package_id',
        'offer_id',
        'service_type',
        'service_id',
        'module_type',
        'package_role',
        'is_required',
        'sort_order',
        'notes',
        'selection_mode',
        'price_override',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'price_override' => 'decimal:2',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
