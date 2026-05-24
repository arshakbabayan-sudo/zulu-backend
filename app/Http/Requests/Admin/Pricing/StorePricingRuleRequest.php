<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use App\Models\PricingRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_super_admin === true;
    }

    public function rules(): array
    {
        $scopeType = $this->input('scope_type');

        return [
            'scope_type' => ['required', 'string', Rule::in([
                PricingRule::SCOPE_PARTNERSHIP,
                PricingRule::SCOPE_OPERATOR,
                PricingRule::SCOPE_CATEGORY,
                PricingRule::SCOPE_GLOBAL,
            ])],
            'operator_id' => [
                Rule::requiredIf(in_array($scopeType, [PricingRule::SCOPE_PARTNERSHIP, PricingRule::SCOPE_OPERATOR], true)),
                'nullable',
                'integer',
                'exists:companies,id',
            ],
            'agent_id' => [
                Rule::requiredIf($scopeType === PricingRule::SCOPE_PARTNERSHIP),
                'nullable',
                'integer',
                'exists:companies,id',
            ],
            'service_category' => [
                Rule::requiredIf($scopeType === PricingRule::SCOPE_CATEGORY),
                'nullable',
                Rule::in(['hotel', 'flight', 'transfer', 'car', 'excursion', 'package', 'visa']),
            ],
            'destination_id' => ['nullable', 'integer', 'exists:locations,id'],
            'markup_type' => ['required', Rule::in([PricingRule::TYPE_PERCENTAGE, PricingRule::TYPE_FIXED])],
            'markup_value' => ['required', 'numeric', 'min:0'],
            'min_sell_amount' => ['nullable', 'numeric', 'min:0'],
            'max_sell_amount' => ['nullable', 'numeric', 'min:0', 'gte:min_sell_amount'],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
