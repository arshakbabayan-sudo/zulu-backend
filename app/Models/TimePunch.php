<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimePunch extends Model
{
    use HasFactory;

    public const SOURCES = ['self', 'manager', 'system'];

    protected $table = 'time_punches';

    protected $fillable = [
        'company_id',
        'user_id',
        'punched_in_at',
        'punched_out_at',
        'minutes_worked',
        'source',
        'created_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'punched_in_at' => 'datetime',
            'punched_out_at' => 'datetime',
            'minutes_worked' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->punched_out_at === null;
    }
}
