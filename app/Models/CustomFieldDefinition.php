<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.4 — operator-defined custom field.
 */
class CustomFieldDefinition extends Model
{
    use HasFactory;

    public const FIELD_TYPES = ['text', 'number', 'boolean', 'select', 'multi_select', 'date'];

    public const SCOPES = ['all', 'hotel', 'flight', 'car', 'transfer', 'excursion', 'visa', 'package'];

    protected $table = 'custom_field_definitions';

    protected $fillable = [
        'company_id',
        'scope',
        'key',
        'label',
        'field_type',
        'options',
        'help_text',
        'is_required',
        'show_in_filter',
        'display_order',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'show_in_filter' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
