<?php

namespace App\Services\Visas;

use App\Models\Location;
use App\Models\Visa;
use App\Services\Locations\LocationBusinessValidator;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisaService
{
    /**
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'location_id',
        'country',
        'visa_type',
    ];

    /**
     * Validation rules for `required_documents` on POST /visas.
     *
     * @return list<string|Closure>
     */
    public static function storeRequiredDocumentsRules(): array
    {
        return ['nullable', self::requiredDocumentsValueRule()];
    }

    /**
     * Validation rules for `required_documents` on PATCH /visas/{visa} (partial).
     *
     * @return list<string|Closure>
     */
    public static function updateRequiredDocumentsRules(): array
    {
        return ['sometimes', 'nullable', self::requiredDocumentsValueRule()];
    }

    /**
     * Accepts null, a PHP array, or a JSON string that decodes to an array (for storage as JSON).
     */
    public static function normalizeRequiredDocuments(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private static function requiredDocumentsValueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }
            if (is_array($value)) {
                return;
            }
            if (! is_string($value)) {
                $fail('The :attribute must be an array or a JSON string.');

                return;
            }
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                $fail('The :attribute must be a JSON array string when sent as a string.');
            }
        };
    }

    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, Visa>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        if ($companyIds === []) {
            return Visa::query()->whereRaw('0 = 1')->get();
        }

        $query = Visa::query()
            ->with('offer')
            ->whereHas('offer', function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds);
            })
            ->orderBy('id');

        $this->applyListingFilters($query, $filters);

        return $query->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        if ($companyIds === []) {
            return Visa::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        $query = Visa::query()
            ->with('offer')
            ->whereHas('offer', function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds);
            })
            ->orderBy('id');

        $this->applyListingFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function listingFiltersFromRequest(Request $request): array
    {
        $filters = [];
        foreach (self::LISTING_FILTER_KEYS as $key) {
            if ($request->query->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Visa $visa, array $data): Visa
    {
        $filtered = array_intersect_key($data, array_flip([
            'country',
            'visa_type',
            'processing_days',
            'name',
            'description',
            'required_documents',
            'price',
            'country_id',
            'location_id',
        ]));

        $validator = Validator::make($filtered, [
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'visa_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'processing_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'required_documents' => self::updateRequiredDocumentsRules(),
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'country_id' => ['sometimes', 'nullable', 'integer', Rule::exists('countries', 'id')],
            'location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('locations', 'id')],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $this->validateVisaLocationBusinessRules(array_merge([
            'location_id' => $visa->location_id,
        ], $validated));
        $resolvedLocationId = isset($validated['location_id'])
            ? (int) $validated['location_id']
            : (int) $visa->location_id;
        $validated = array_merge($validated, $this->deriveDeprecatedVisaLocationFields($resolvedLocationId));
        if (array_key_exists('required_documents', $validated)) {
            $validated['required_documents'] = self::normalizeRequiredDocuments($validated['required_documents']);
        }
        $validated = Arr::only($validated, $this->existingVisaColumns(array_keys($validated)));
        if ($validated !== []) {
            $visa->fill($validated);
            $visa->save();
        }

        return $visa->fresh();
    }

    public function delete(Visa $visa): void
    {
        $visa->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyListingFilters(Builder $query, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        $table = $query->getModel()->getTable();

        $locationId = $this->normalizeListingInt($filters['location_id'] ?? null);
        if ($locationId !== null) {
            $query->forLocation($locationId);
        }

        // Deprecated: textual country filter was removed after location tree cutover.

        $visaType = $this->normalizeListingString($filters['visa_type'] ?? null);
        if ($visaType !== null) {
            $query->where($table.'.visa_type', 'like', '%'.addcslashes($visaType, '%_\\').'%');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validateVisaLocationBusinessRules(array $payload): void
    {
        app(LocationBusinessValidator::class)->requireLocationOfTypes(
            isset($payload['location_id']) ? (int) $payload['location_id'] : null,
            'location_id',
            [Location::TYPE_COUNTRY],
            'Visa requires a country-level location.',
            'Visa location must be a country.'
        );
    }

    /**
     * Legacy columns are kept read-only for rollout safety and are derived from location tree.
     *
     * @return array{country: string|null}
     */
    public function deriveDeprecatedVisaLocationFields(int $locationId): array
    {
        $location = Location::query()->find($locationId);
        if ($location === null) {
            return $this->onlyExistingLegacyColumns('visas', ['country' => null]);
        }

        $lineage = $location->ancestors()->push($location)->values();

        return $this->onlyExistingLegacyColumns('visas', [
            'country' => optional($lineage->firstWhere('type', Location::TYPE_COUNTRY))->name,
        ]);
    }

    private function normalizeListingInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_numeric($value)) {
            $n = (int) $value;

            return $n > 0 ? $n : null;
        }

        return null;
    }

    private function normalizeListingString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function onlyExistingLegacyColumns(string $table, array $payload): array
    {
        $existingKeys = [];
        foreach (array_keys($payload) as $column) {
            if (Schema::hasColumn($table, $column)) {
                $existingKeys[] = $column;
            }
        }

        return Arr::only($payload, $existingKeys);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function existingVisaColumns(array $columns): array
    {
        $existing = [];
        foreach ($columns as $column) {
            if (Schema::hasColumn('visas', $column)) {
                $existing[] = $column;
            }
        }

        return $existing;
    }
}
