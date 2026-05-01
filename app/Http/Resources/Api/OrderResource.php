<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'buyer_type' => $this->buyer_type,
            'status' => $this->status,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal !== null ? (float) $this->subtotal : null,
            'tax' => $this->tax !== null ? (float) $this->tax : null,
            'total' => $this->total !== null ? (float) $this->total : null,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->values()),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            'company' => $this->whenLoaded('company', function () {
                return [
                    'id' => $this->company->id,
                    'name' => $this->company->name,
                    'slug' => $this->company->slug,
                ];
            }),
        ];
    }
}
