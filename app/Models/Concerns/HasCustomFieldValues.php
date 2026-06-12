<?php

namespace App\Models\Concerns;

use App\Models\CustomFieldValue;

/**
 * Purges this entity's custom field values when the entity row is deleted.
 * Offer-level DB cascades bypass model events, leaving unreachable orphans —
 * harmless (ids are never reused) but model-path deletes stay clean.
 */
trait HasCustomFieldValues
{
    public static function bootHasCustomFieldValues(): void
    {
        static::deleted(function ($model): void {
            CustomFieldValue::query()
                ->where('entity_type', $model->customFieldScope())
                ->where('entity_id', (int) $model->getKey())
                ->delete();
        });
    }

    /** The custom-field scope vertical this model belongs to. */
    abstract public function customFieldScope(): string;
}
