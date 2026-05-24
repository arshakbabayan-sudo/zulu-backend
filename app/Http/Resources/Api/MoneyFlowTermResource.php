<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\MoneyFlowTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MoneyFlowTerm
 */
class MoneyFlowTermResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope_type' => $this->scope_type,
            'operator_id' => $this->operator_id,
            'operator_name' => $this->operator?->name,
            'agent_id' => $this->agent_id,
            'agent_name' => $this->agent?->name,
            'collection_model' => $this->collection_model,
            'remittance_days' => $this->remittance_days,
            'invoicing_period' => $this->invoicing_period,
            'is_active' => (bool) $this->is_active,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_until' => $this->effective_until?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
