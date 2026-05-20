<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Passenger extends Model
{
    use HasFactory;

    public const TYPES = ['adult', 'child', 'infant'];

    public const GENDERS = ['male', 'female', 'other'];

    protected $fillable = [
        'first_name',
        'last_name',
        'passport_number',
        'passport_expiry',
        'nationality',
        'date_of_birth',
        'gender',
        'passenger_type',
        'email',
        'phone',
    ];

    protected function casts(): array
    {
        return [
            'passport_expiry' => 'date',
            'date_of_birth' => 'date',
            // GDPR Article 32: PII at-rest encryption. See migration
            // 2026_05_21_000000_encrypt_pii_passport_and_nationality.
            'passport_number' => 'encrypted',
            'nationality' => 'encrypted',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
