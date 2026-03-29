<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceConnection extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const SOURCE_TYPES = ['flight', 'hotel', 'transfer'];

    /** @var list<string> */
    public const TARGET_TYPES = ['flight', 'hotel', 'transfer'];

    /** @var list<string> */
    public const STATUSES = ['pending', 'accepted', 'rejected', 'canceled'];

    /** @var list<string> */
    public const CLIENT_TARGETING = ['all', 'selected'];

    /** @var list<string> */
    protected $fillable = [
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'connection_type',
        'status',
        'client_targeting',
        'company_id',
        'notes',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
