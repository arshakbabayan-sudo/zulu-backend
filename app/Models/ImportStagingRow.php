<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportStagingRow extends Model
{
    public const VALIDATION_PENDING = 'pending';

    public const VALIDATION_OK = 'ok';

    public const VALIDATION_INVALID = 'invalid';

    public const COMMIT_PENDING = 'pending';

    protected $fillable = [
        'import_session_id',
        'entity_type',
        'sheet_name',
        'row_number',
        'external_key',
        'parent_external_key',
        'payload_json',
        'validation_status',
        'validation_errors_json',
        'commit_status',
        'commit_errors_json',
        'committed_entity_id',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'validation_errors_json' => 'array',
            'commit_errors_json' => 'array',
        ];
    }

    public function importSession(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class, 'import_session_id');
    }
}
