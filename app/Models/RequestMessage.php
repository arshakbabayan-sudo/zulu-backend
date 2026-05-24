<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.7 follow-up — reply thread message on an agent ↔ operator request.
 */
class RequestMessage extends Model
{
    use HasFactory;

    protected $table = 'request_messages';

    protected $fillable = [
        'request_id',
        'sender_id',
        'body',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(AgentOperatorRequest::class, 'request_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
