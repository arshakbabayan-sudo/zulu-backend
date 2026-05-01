<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContractTemplate extends Model
{
    use HasUuids;

    protected $table = 'contract_templates';

    protected $keyType = 'string';

    public $incrementing = false;

    public const TYPES = ['platform', 'partner_supplier', 'partner_reseller', 'partner_default'];

    public const LANGUAGES = ['hy', 'en', 'ru'];

    protected $fillable = [
        'name',
        'type',
        'language',
        'version',
        'body',
        'variables',
        'active',
    ];

    protected $casts = [
        'variables' => 'array',
        'active' => 'boolean',
        'version' => 'integer',
    ];
}
