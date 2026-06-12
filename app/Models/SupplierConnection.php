<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roadmap §4 — link between an operator company and an external inventory
 * base (the ZULU supplier-connector contract). Credentials encrypted at rest.
 */
class SupplierConnection extends Model
{
    public const STATUS_UNTESTED = 'untested';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $table = 'supplier_connections';

    protected $fillable = [
        'company_id',
        'name',
        'base_url',
        'login',
        'password',
        'status',
        'last_tested_at',
        'last_synced_at',
        'last_error',
        'items_imported',
        'created_by_user_id',
    ];

    protected $hidden = ['login', 'password'];

    protected function casts(): array
    {
        return [
            'login' => 'encrypted',
            'password' => 'encrypted',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'items_imported' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function importedItems(): HasMany
    {
        return $this->hasMany(SupplierImportedItem::class);
    }
}
