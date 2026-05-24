<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use App\Models\MoneyFlowTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMoneyFlowTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_super_admin === true;
    }

    public function rules(): array
    {
        $scopeType = $this->input('scope_type');
        $collectionModel = $this->input('collection_model');

        return [
            'scope_type' => ['required', Rule::in([
                MoneyFlowTerm::SCOPE_PARTNERSHIP,
                MoneyFlowTerm::SCOPE_OPERATOR,
                MoneyFlowTerm::SCOPE_GLOBAL,
            ])],
            'operator_id' => [
                Rule::requiredIf(in_array($scopeType, [MoneyFlowTerm::SCOPE_PARTNERSHIP, MoneyFlowTerm::SCOPE_OPERATOR], true)),
                'nullable', 'integer', 'exists:companies,id',
            ],
            'agent_id' => [
                Rule::requiredIf($scopeType === MoneyFlowTerm::SCOPE_PARTNERSHIP),
                'nullable', 'integer', 'exists:companies,id',
            ],
            'collection_model' => ['required', Rule::in([
                MoneyFlowTerm::MODEL_ZULU_COLLECTS,
                MoneyFlowTerm::MODEL_OPERATOR_COLLECTS,
                MoneyFlowTerm::MODEL_AGENT_COLLECTS,
            ])],
            'remittance_days' => [
                Rule::requiredIf($collectionModel === MoneyFlowTerm::MODEL_ZULU_COLLECTS),
                'nullable', 'integer', 'min:0', 'max:365',
            ],
            'invoicing_period' => [
                Rule::requiredIf($collectionModel === MoneyFlowTerm::MODEL_OPERATOR_COLLECTS),
                'nullable', Rule::in(['weekly', 'monthly']),
            ],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
