<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use App\Models\MoneyFlowTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMoneyFlowTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_super_admin === true;
    }

    public function rules(): array
    {
        return [
            'scope_type' => ['sometimes', Rule::in([
                MoneyFlowTerm::SCOPE_PARTNERSHIP,
                MoneyFlowTerm::SCOPE_OPERATOR,
                MoneyFlowTerm::SCOPE_GLOBAL,
            ])],
            'operator_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'agent_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'collection_model' => ['sometimes', Rule::in([
                MoneyFlowTerm::MODEL_ZULU_COLLECTS,
                MoneyFlowTerm::MODEL_OPERATOR_COLLECTS,
                MoneyFlowTerm::MODEL_AGENT_COLLECTS,
            ])],
            'remittance_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'invoicing_period' => ['sometimes', 'nullable', Rule::in(['weekly', 'monthly'])],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
