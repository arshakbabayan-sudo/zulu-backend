<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    public const SERVICE_AIR_TICKET = 'air_ticket';
    public const SERVICE_HOTEL = 'hotel';
    public const SERVICE_TRANSFER = 'transfer';
    public const SERVICE_PACKAGE = 'package';
    public const SERVICE_CAR_RENT = 'car_rent';
    public const SERVICE_EXCURSION = 'excursion';

    public const SERVICE_TYPES = [
        self::SERVICE_AIR_TICKET,
        self::SERVICE_HOTEL,
        self::SERVICE_TRANSFER,
        self::SERVICE_PACKAGE,
        self::SERVICE_CAR_RENT,
        self::SERVICE_EXCURSION,
    ];

    protected $fillable = [
        'company_id',
        'service_type',
        'commission_type',
        'value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
