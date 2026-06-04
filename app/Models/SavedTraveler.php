<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A B2C customer's saved traveler / companion (incl. themselves) — reused to
 * auto-fill bookings. See the account "Travelers" + "Travel documents" sections.
 */
class SavedTraveler extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'nationality',
        'relationship',
        'is_self',
        'passport_number',
        'passport_country',
        'passport_expiry',
        'email',
        'phone',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'passport_expiry' => 'date',
        'is_self' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
