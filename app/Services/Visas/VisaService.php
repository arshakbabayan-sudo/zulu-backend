<?php

namespace App\Services\Visas;

use App\Models\Visa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VisaService
{
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
        $filtered = array_intersect_key($data, array_flip(['country', 'visa_type', 'processing_days']));

        $validator = Validator::make($filtered, [
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'visa_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'processing_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
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
