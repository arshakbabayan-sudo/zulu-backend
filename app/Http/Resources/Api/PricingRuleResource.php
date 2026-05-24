<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\PricingRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PricingRule
 */
class PricingRuleResource extends JsonResource
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
            'service_category' => $this->service_category,
            'destination_id' => $this->destination_id,
            'markup_type' => $this->markup_type,
            'markup_value' => $this->markup_value === null ? null : (float) $this->markup_value,
            'min_sell_amount' => $this->min_sell_amount === null ? null : (float) $this->min_sell_amount,
            'max_sell_amount' => $this->max_sell_amount === null ? null : (float) $this->max_sell_amount,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_until' => $this->effective_until?->toIso8601String(),
            'priority' => $this->priority,
            'is_active' => (bool) $this->is_active,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
