<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\Concerns\HasLocationValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class ProductLocationRequest extends FormRequest
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
        ];
    }
}

