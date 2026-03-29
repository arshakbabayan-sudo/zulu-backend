<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'currency' => $this->currency,
            'total_gross_amount' => (float) $this->total_gross_amount,
            'total_commission_amount' => (float) $this->total_commission_amount,
            'total_net_amount' => (float) $this->total_net_amount,
            'entitlements_count' => (int) $this->entitlements_count,
            'status' => $this->status,
            'period_label' => $this->period_label,
            'settled_at' => $this->settled_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'company' => $this->whenLoaded('company', function () {
                return [
                    'id' => $this->company->id,
                    'name' => $this->company->name,
                ];
            }),
            'entitlements' => $this->whenLoaded(
                'entitlements',
                fn () => SupplierEntitlementResource::collection($this->entitlements)->resolve()
            ),
        ];
    }
}
