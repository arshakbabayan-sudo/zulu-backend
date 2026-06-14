<?php

namespace App\Http\Resources\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
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
            // Lightweight package summary for B2C booking lists (My-account → Bookings).
            // Populated only when the package relation was eager-loaded (items.package);
            // returns null otherwise so we never trigger an N+1 lazy-load here.
            'package' => $this->whenLoaded('items', function () {
                $first = $this->items->first();
                if ($first === null || ! $first->relationLoaded('package') || $first->package === null) {
                    return null;
                }

                return [
                    'package_title' => $first->package->package_title,
                    'destination_city' => $first->package->destination_city,
                    'destination_country' => $first->package->destination_country,
                ];
            }),
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
