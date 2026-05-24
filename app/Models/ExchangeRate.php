<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    public const SOURCE_CBA = 'cba';

    public const SOURCE_ECB = 'ecb';

    public const SOURCE_EXCHANGERATE_API = 'exchangerate_api';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PARTNER_OVERRIDE = 'partner_override';

    /**
     * Source precedence — highest wins when multiple is_active=true rows
     * exist for the same (source_currency, target_currency) pair.
     */
    public const SOURCE_PRECEDENCE = [
        self::SOURCE_PARTNER_OVERRIDE,
        self::SOURCE_MANUAL,
        self::SOURCE_CBA,
        self::SOURCE_ECB,
        self::SOURCE_EXCHANGERATE_API,
    ];

    protected $table = 'exchange_rates';

    public $timestamps = false; // only created_at; no updated_at

    protected $fillable = [
        'source_currency',
        'target_currency',
        'rate',
        'source',
        'fetched_at',
        'is_active',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'fetched_at' => 'datetime',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForPair(Builder $q, string $source, string $target): Builder
    {
        return $q->where('source_currency', strtoupper($source))
            ->where('target_currency', strtoupper($target));
    }
}
