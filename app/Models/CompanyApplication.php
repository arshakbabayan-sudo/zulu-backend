<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyApplication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_name',
        'business_email',
        'legal_address',
        'actual_address',
        'country',
        'city',
        'phone',
        'tax_id',
        'contact_person',
        'position',
        'state_certificate_path',
        'license_path',
        'status',
        'rejection_reason',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'notes',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
