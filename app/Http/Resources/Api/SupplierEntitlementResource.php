<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierEntitlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'package_order_id' => $this->package_order_id,
            'package_order_item_id' => $this->package_order_item_id,
            'company_id' => $this->company_id,
            'service_type' => $this->service_type,
            'gross_amount' => (float) $this->gross_amount,
            'commission_amount' => (float) $this->commission_amount,
            'net_amount' => (float) $this->net_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'settlement_id' => $this->settlement_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'order' => $this->whenLoaded('order', function () {
                return [
                    'id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                    'status' => $this->order->status,
                ];
            }),
        ];
    }
}
