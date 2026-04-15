<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait HasLocationValidationRules
{
    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    protected function nullableLocationIdRules(): array
    {
        return ['sometimes', 'nullable', 'integer', Rule::exists('locations', 'id')];
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    protected function requiredLocationIdRules(): array
    {
        return ['required', 'integer', Rule::exists('locations', 'id')];
    }
}

