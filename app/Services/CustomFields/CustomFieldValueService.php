<?php

namespace App\Services\CustomFields;

use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Roadmap §4 — validates and persists operator-defined custom field VALUES
 * attached to inventory entities (hotel / flight / car / transfer /
 * excursion / visa / package).
 *
 * Definitions are company-scoped (custom_field_definitions); the company
 * that owns the ENTITY (offer->company_id) decides which definitions apply,
 * so a super admin editing an operator's hotel validates against that
 * operator's fields. Scope 'all' definitions apply to every vertical.
 */
class CustomFieldValueService
{
    public const TEXT_MAX = 2000;

    /**
     * Validate a raw `custom_fields` payload against the entity company's
     * ACTIVE definitions and normalize it for storage.
     *
     * @param  array<string, mixed>|null  $values  Raw payload; null = key absent from the request.
     * @return array<int, mixed>|null Normalized values keyed by definition id
     *                                (null value = clear). Null when nothing should
     *                                be synced (key absent on a partial update).
     *
     * @throws ValidationException
     */
    public function validateForWrite(int $companyId, string $scope, ?array $values, bool $isCreate): ?array
    {
        if ($values === null) {
            if (! $isCreate) {
                return null;
            }
            $values = [];
        }

        $definitions = CustomFieldDefinition::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('scope', [$scope, 'all'])
            ->get();

        $byKey = $definitions->keyBy('key');
        $errors = [];
        $normalized = [];

        foreach ($values as $key => $raw) {
            $definition = $byKey->get((string) $key);
            if ($definition === null) {
                $errors["custom_fields.{$key}"] = ["Unknown custom field '{$key}'."];

                continue;
            }

            try {
                $normalized[$definition->id] = $this->normalize($definition, $raw);
            } catch (InvalidArgumentException $e) {
                $errors["custom_fields.{$key}"] = [$e->getMessage()];
            }
        }

        foreach ($definitions as $definition) {
            if (! $definition->is_required || isset($errors["custom_fields.{$definition->key}"])) {
                continue;
            }
            $present = array_key_exists($definition->key, $values);
            $empty = ($normalized[$definition->id] ?? null) === null;
            if (($isCreate && $empty) || (! $isCreate && $present && $empty)) {
                $errors["custom_fields.{$definition->key}"] = ["'{$definition->label}' is required."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * Upsert normalized values for one entity. Keys absent from the map are
     * left untouched (partial-update semantics); null values delete the row.
     *
     * @param  array<int, mixed>  $normalizedByDefinitionId
     */
    public function sync(string $scope, int $entityId, array $normalizedByDefinitionId): void
    {
        foreach ($normalizedByDefinitionId as $definitionId => $value) {
            if ($value === null) {
                CustomFieldValue::query()
                    ->where('custom_field_definition_id', $definitionId)
                    ->where('entity_type', $scope)
                    ->where('entity_id', $entityId)
                    ->delete();

                continue;
            }

            CustomFieldValue::query()->updateOrCreate(
                [
                    'custom_field_definition_id' => $definitionId,
                    'entity_type' => $scope,
                    'entity_id' => $entityId,
                ],
                ['value' => $value],
            );
        }
    }

    /**
     * Stored values for one entity as a {definition key => value} map,
     * pinned to the entity company's definitions.
     *
     * @return array<string, mixed>
     */
    public function valuesFor(int $companyId, string $scope, int $entityId): array
    {
        return CustomFieldValue::query()
            ->where('entity_type', $scope)
            ->where('entity_id', $entityId)
            ->whereHas('definition', fn ($q) => $q->where('company_id', $companyId))
            ->with('definition:id,key')
            ->get()
            ->mapWithKeys(fn (CustomFieldValue $row) => [$row->definition->key => $row->value])
            ->all();
    }

    /**
     * Normalize one raw value by the definition's field type.
     * Null result means "no value" (clears any stored row).
     *
     * @throws InvalidArgumentException
     */
    private function normalize(CustomFieldDefinition $definition, mixed $raw): mixed
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        switch ($definition->field_type) {
            case 'text':
                if (! is_scalar($raw) || is_bool($raw)) {
                    throw new InvalidArgumentException('Must be text.');
                }
                $value = (string) $raw;
                if (mb_strlen($value) > self::TEXT_MAX) {
                    throw new InvalidArgumentException('Text is too long (max '.self::TEXT_MAX.' characters).');
                }

                return $value;

            case 'number':
                if (! is_numeric($raw)) {
                    throw new InvalidArgumentException('Must be a number.');
                }

                return $raw + 0;

            case 'boolean':
                if (is_bool($raw)) {
                    return $raw;
                }
                if (in_array($raw, [0, 1, '0', '1', 'true', 'false'], true)) {
                    return in_array($raw, [1, '1', 'true'], true);
                }
                throw new InvalidArgumentException('Must be true or false.');

            case 'date':
                if (! is_string($raw)) {
                    throw new InvalidArgumentException('Must be a date (YYYY-MM-DD).');
                }
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
                    throw new InvalidArgumentException('Must be a date (YYYY-MM-DD).');
                }

                return $raw;

            case 'select':
                $options = $definition->options ?? [];
                if (! is_string($raw) || ! in_array($raw, $options, true)) {
                    throw new InvalidArgumentException('Must be one of: '.implode(', ', $options).'.');
                }

                return $raw;

            case 'multi_select':
                $options = $definition->options ?? [];
                if (! is_array($raw)) {
                    throw new InvalidArgumentException('Must be a list of options.');
                }
                $picked = [];
                foreach ($raw as $item) {
                    if (! is_string($item) || ! in_array($item, $options, true)) {
                        throw new InvalidArgumentException('Every item must be one of: '.implode(', ', $options).'.');
                    }
                    if (! in_array($item, $picked, true)) {
                        $picked[] = $item;
                    }
                }

                return $picked === [] ? null : $picked;

            default:
                throw new InvalidArgumentException('Unsupported field type.');
        }
    }
}
