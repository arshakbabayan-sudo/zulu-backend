<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A B2C customer's own travel document (passport / ID / driver's licence /
 * loyalty number / …). Account "Travel documents" section.
 */
class TravelDocument extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'label',
        'number',
        'issuing_country',
        'holder_name',
        'issue_date',
        'expiry_date',
        'file_path',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
