<?php

namespace App\Http\Controllers\Concerns;

use App\Services\CustomFields\CustomFieldValueService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Roadmap §4 — shared `custom_fields` request handling for the 7 inventory
 * vertical controllers. Validate BEFORE the entity write (so a 422 never
 * leaves a half-created entity), sync AFTER it succeeds.
 */
trait HandlesCustomFieldValues
{
    /**
     * @return array<int, mixed>|null Normalized values keyed by definition id,
     *                                or null when the request doesn't touch custom fields.
     *
     * @throws ValidationException
     */
    private function validateCustomFields(Request $request, int $companyId, string $scope, bool $isCreate): ?array
    {
        $raw = $request->input('custom_fields');
        if ($raw !== null && ! is_array($raw)) {
            throw ValidationException::withMessages([
                'custom_fields' => ['custom_fields must be an object of key/value pairs.'],
            ]);
        }

        return app(CustomFieldValueService::class)->validateForWrite($companyId, $scope, $raw, $isCreate);
    }

    /**
     * @param  array<int, mixed>|null  $normalized
     */
    private function syncCustomFields(?array $normalized, string $scope, int $entityId): void
    {
        if ($normalized === null || $normalized === []) {
            return;
        }

        app(CustomFieldValueService::class)->sync($scope, $entityId, $normalized);
    }
}
