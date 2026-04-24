<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionResolutionLog extends Model
{
    protected $table = 'commission_resolution_log';

    protected $fillable = [
        'transaction_id',
        'candidate_rules',
        'chosen_rule_id',
        'reason',
    ];

    protected $casts = [
        'candidate_rules' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CommissionTransaction::class, 'transaction_id');
    }

    public function chosenRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'chosen_rule_id');
    }
}
