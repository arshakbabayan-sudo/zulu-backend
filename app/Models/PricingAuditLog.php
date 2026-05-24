<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingAuditLog extends Model
{
    use HasFactory;

    public const ENTITY_PRICING_RULE = 'pricing_rule';

    public const ENTITY_MONEY_FLOW_TERM = 'money_flow_term';

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DEACTIVATED = 'deactivated';

    public const ACTION_REACTIVATED = 'reactivated';

    public const ACTION_DELETED = 'deleted';

    protected $table = 'pricing_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'old_values',
        'new_values',
        'changed_by',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
