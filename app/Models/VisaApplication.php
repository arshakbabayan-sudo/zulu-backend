<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisaApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'visa_id',
        'status',
        'passport_number',
        'entry_date',
        'exit_date',
        'files',
        'admin_notes',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'exit_date' => 'date',
        'files' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visa(): BelongsTo
    {
        return $this->belongsTo(Visa::class);
    }
}
