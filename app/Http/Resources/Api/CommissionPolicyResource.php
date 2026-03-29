<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'service_type' => $this->service_type,
            'percent' => (float) $this->percent,
            'commission_mode' => $this->commission_mode,
            'min_amount' => $this->min_amount !== null ? (float) $this->min_amount : null,
            'max_amount' => $this->max_amount !== null ? (float) $this->max_amount : null,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
