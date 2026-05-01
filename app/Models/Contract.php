<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'contracts';

    protected $keyType = 'string';

    public $incrementing = false;

    public const TYPES = ['platform', 'partner'];

    public const STATUSES = [
        'draft',
        'sent',
        'signed_by_a',
        'signed_by_b',
        'countersigned',
        'active',
        'expired',
        'terminated',
        'disputed',
    ];

    public const LANGUAGES = ['hy', 'en', 'ru'];

    protected $fillable = [
        'contract_number',
        'type',
        'party_a_company_id',
        'party_b_company_id',
        'template_id',
        'status',
        'commission_clause',
        'payment_terms',
        'cancellation_policy',
        'rendered_body',
        'effective_date',
        'expiry_date',
        'auto_renew',
        'termination_notice_days',
        'signed_pdf_url',
        'signatures',
        'termination_reason',
        'terminated_at',
        'created_by_user_id',
        'language',
    ];

    protected $casts = [
        'commission_clause' => 'array',
        'payment_terms' => 'array',
        'cancellation_policy' => 'array',
        'rendered_body' => 'array',
        'signatures' => 'array',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'terminated_at' => 'datetime',
        'auto_renew' => 'boolean',
        'termination_notice_days' => 'integer',
    ];

    public function partyA(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'party_a_company_id');
    }

    public function partyB(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'party_b_company_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class, 'contract_id')->orderByDesc('version_number');
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active' && $this->status !== 'countersigned') {
            return false;
        }

        if ($this->expiry_date !== null && $this->expiry_date->isPast()) {
            return false;
        }

        return true;
    }

    public function isPlatformContract(): bool
    {
        return $this->type === 'platform';
    }

    public function isPartnerAgreement(): bool
    {
        return $this->type === 'partner';
    }
}
