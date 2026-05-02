<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SagaStateLog extends Model
{
    protected $table = 'saga_state_log';

    public $timestamps = true;

    protected $fillable = [
        'saga_id',
        'from_status',
        'to_status',
        'event',
        'component_state_id',
        'details',
        'happened_at',
    ];

    protected $casts = [
        'details' => 'array',
        'happened_at' => 'datetime',
    ];

    public function saga(): BelongsTo
    {
        return $this->belongsTo(PackageBookingSaga::class, 'saga_id');
    }

    public function componentState(): BelongsTo
    {
        return $this->belongsTo(SagaComponentState::class, 'component_state_id');
    }
}
