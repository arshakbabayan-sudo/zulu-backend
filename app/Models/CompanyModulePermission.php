<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6A — admin-side module visibility per company.
 *
 * One row per (company, module_key). Default behaviour when no row exists is
 * "allowed" — the sidebar must only hide tabs for which an explicit
 * `is_allowed=false` row is found. Keeping module_key as a free-form string
 * (not an enum) means adding modules requires no migration, only frontend
 * wiring. The canonical list of well-known keys lives in {@see self::MODULE_KEYS}
 * so super-admin UI can render a checkbox grid without a separate config.
 */
class CompanyModulePermission extends Model
{
    use HasFactory;

    /**
     * Well-known module keys. Mirrors the `moduleKey` field of
     * {@see https://github.com/arshakbabayan-sudo/zulu-admin-next admin nav tabs}.
     * Extend as new admin sections come online.
     *
     * @var list<string>
     */
    public const MODULE_KEYS = [
        // Inventory CRUD
        'inventory.hotels',
        'inventory.flights',
        'inventory.cars',
        'inventory.transfers',
        'inventory.excursions',
        'inventory.visas',
        'inventory.packages',
        'inventory.offers',
        // Operations
        'ops.bookings',
        'ops.finance',
        'ops.loyalty',
        'ops.newsletter',
        'ops.reviews',
        'ops.vouchers',
        'ops.connections',
        'ops.contracts',
        'ops.statistics',
    ];

    protected $table = 'company_module_permissions';

    protected $fillable = [
        'company_id',
        'module_key',
        'is_allowed',
        'granted_by_user_id',
        'granted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_allowed' => 'boolean',
            'granted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
