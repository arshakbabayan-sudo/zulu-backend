<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\Concerns\HasLocationValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExcursionLocationsRequest extends FormRequest
{
    use HasLocationValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'location_id' => $this->nullableLocationIdRules(),
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')],
        ];
    }
}

