<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'vouchers';

    protected $keyType = 'string';

    public $incrementing = false;

    public const SERVICE_TYPES = [
        'flight',
        'hotel',
        'transfer',
        'car',
        'excursion',
        'visa',
        'insurance',
        'package',
    ];

    public const STATUSES = [
        'issued',
        'used',
        'void',
        'reissued',
        'expired',
    ];

    public const LANGUAGES = ['hy', 'en', 'ru'];

    protected $fillable = [
        'voucher_number',
        'order_id',
        'order_item_id',
        'service_type',
        'issuer_company_id',
        'holder_name',
        'holder_passport',
        'passenger_data',
        'service_snapshot',
        'qr_token',
        'qr_image_url',
        'pdf_url',
        'status',
        'valid_from',
        'valid_to',
        'used_at',
        'used_by_ip',
        'verification_count',
        'reissued_from_id',
        'language',
    ];

    protected $casts = [
        'passenger_data' => 'array',
        'service_snapshot' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'used_at' => 'datetime',
        'verification_count' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function issuerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'issuer_company_id');
    }

    public function reissuedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissued_from_id');
    }

    public function reissues(): HasMany
    {
        return $this->hasMany(self::class, 'reissued_from_id');
    }

    public function verificationLogs(): HasMany
    {
        return $this->hasMany(VoucherVerificationLog::class, 'voucher_id');
    }

    public function isExpired(): bool
    {
        if ($this->status === 'expired') {
            return true;
        }

        return $this->valid_to !== null && $this->valid_to->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === 'issued' && ! $this->isExpired();
    }
}
