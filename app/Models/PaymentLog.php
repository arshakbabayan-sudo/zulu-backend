<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLog extends Model
{
    protected $fillable = [
        'payment_id',
        'event_type',
        'gateway',
        'gateway_reference',
        'amount',
        'currency',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
