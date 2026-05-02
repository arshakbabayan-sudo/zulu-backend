<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTwoFactor extends Model
{
    protected $table = 'user_two_factor';

    protected $fillable = [
        'user_id',
        'secret_encrypted',
        'recovery_codes_encrypted',
        'enabled_at',
        'confirmed_at',
        'last_verified_at',
    ];

    protected $casts = [
        'enabled_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'recovery_codes_encrypted' => 'encrypted:array',
    ];

    protected $hidden = [
        'secret_encrypted',
        'recovery_codes_encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEnabled(): bool
    {
        return $this->enabled_at !== null && $this->confirmed_at !== null;
    }
}
