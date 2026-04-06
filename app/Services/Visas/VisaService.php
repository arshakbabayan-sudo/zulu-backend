<?php

namespace App\Services\Visas;

use App\Models\Visa;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisaService
{
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
    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return Visa::query()->whereRaw('0 = 1')->get();
        }

        return Visa::query()
            ->with('offer')
            ->whereHas('offer', function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        if ($companyIds === []) {
            return Visa::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        return Visa::query()
            ->with('offer')
            ->whereHas('offer', function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds);
            })
            ->orderBy('id')
            ->paginate($perPage);
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
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        if (array_key_exists('required_documents', $validated)) {
            $validated['required_documents'] = self::normalizeRequiredDocuments($validated['required_documents']);
        }
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
}
