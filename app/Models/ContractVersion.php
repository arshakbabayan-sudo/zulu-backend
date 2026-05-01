<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractVersion extends Model
{
    protected $table = 'contract_versions';

    protected $fillable = [
        'contract_id',
        'version_number',
        'snapshot',
        'changed_by_user_id',
        'changed_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'changed_at' => 'datetime',
        'version_number' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
