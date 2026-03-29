<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPolicy extends Model
{
    use HasFactory;

    public const COMMISSION_MODES = ['percent', 'fixed_amount'];

    public const STATUSES = ['active', 'inactive'];

    protected $table = 'commission_policies';

    protected $fillable = [
        'company_id',
        'service_type',
        'percent',
        'commission_mode',
        'min_amount',
        'max_amount',
        'effective_from',
        'effective_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(CommissionRecord::class, 'commission_policy_id');
    }
}
