<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySellerPermission extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const SERVICE_TYPES = ['flight', 'hotel', 'transfer', 'package', 'excursion', 'car', 'visa'];

    /** @var list<string> */
    public const STATUSES = ['active', 'inactive', 'pending', 'restricted', 'revoked'];

    protected $table = 'company_seller_permissions';

    protected $fillable = [
        'company_id',
        'service_type',
        'status',
        'granted_by',
        'granted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
