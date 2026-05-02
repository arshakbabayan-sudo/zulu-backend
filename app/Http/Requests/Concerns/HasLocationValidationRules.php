<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait HasLocationValidationRules
{
    /**
     * @return array<int, ValidationRule|string>
     */
    protected function nullableLocationIdRules(): array
    {
        return ['sometimes', 'nullable', 'integer', Rule::exists('locations', 'id')];
    }

    /**
     * @return array<int, ValidationRule|string>
     */
    protected function requiredLocationIdRules(): array
    {
        return ['required', 'integer', Rule::exists('locations', 'id')];
    }
}
