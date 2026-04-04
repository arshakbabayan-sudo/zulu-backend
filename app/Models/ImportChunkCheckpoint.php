<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportChunkCheckpoint extends Model
{
    /**
     * Empty string means not scoped to a specific entity type (matches DB default for unique key safety).
     */
    public const ENTITY_TYPE_GLOBAL = '';

    protected $fillable = [
        'import_session_id',
        'phase',
        'chunk_index',
        'entity_type',
        'status',
        'processed_at',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function importSession(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class, 'import_session_id');
    }
}
