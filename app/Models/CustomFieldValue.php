<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Roadmap §4 — a single custom field value attached to one inventory entity.
 *
 * entity_type = vertical scope string ('hotel' | ... | 'package'),
 * entity_id = that vertical table's integer PK.
 */
class CustomFieldValue extends Model
{
    protected $table = 'custom_field_values';

    protected $fillable = [
        'custom_field_definition_id',
        'entity_type',
        'entity_id',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'custom_field_definition_id');
    }
}
