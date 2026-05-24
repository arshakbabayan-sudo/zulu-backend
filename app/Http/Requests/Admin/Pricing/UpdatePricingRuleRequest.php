<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use App\Models\PricingRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_super_admin === true;
    }

    public function rules(): array
    {
        // Update accepts partial payloads — every field is `sometimes`.
        return [
            'scope_type' => ['sometimes', 'string', Rule::in([
                PricingRule::SCOPE_PARTNERSHIP,
                PricingRule::SCOPE_OPERATOR,
                PricingRule::SCOPE_CATEGORY,
                PricingRule::SCOPE_GLOBAL,
            ])],
            'operator_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'agent_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'service_category' => ['sometimes', 'nullable', Rule::in(['hotel', 'flight', 'transfer', 'car', 'excursion', 'package', 'visa'])],
            'destination_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'markup_type' => ['sometimes', Rule::in([PricingRule::TYPE_PERCENTAGE, PricingRule::TYPE_FIXED])],
            'markup_value' => ['sometimes', 'numeric', 'min:0'],
            'min_sell_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_sell_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'effective_from' => ['sometimes', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
