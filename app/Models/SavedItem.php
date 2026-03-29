<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedItem extends Model
{
    /** @var list<string> */
    public const MODULE_TYPES = [
        'flight',
        'hotel',
        'transfer',
        'car',
        'excursion',
        'package',
        'visa',
    ];

    /** @var list<string> */
    public const STATUSES = ['active', 'removed'];

    protected $fillable = [
        'user_id',
        'offer_id',
        'module_type',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
