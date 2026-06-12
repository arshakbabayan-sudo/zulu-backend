<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Roadmap §4 — idempotency ledger row: external item → local offer.
 */
class SupplierImportedItem extends Model
{
    protected $table = 'supplier_imported_items';

    protected $fillable = [
        'supplier_connection_id',
        'item_type',
        'external_id',
        'offer_id',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SupplierConnection::class, 'supplier_connection_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
