<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Car
 */
class CarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $this->whenLoaded('offer', fn () => $this->offer !== null ? (int) $this->offer->company_id : null),
            'pickup_location' => $this->pickup_location,
            'dropoff_location' => $this->dropoff_location,
            'vehicle_class' => $this->vehicle_class,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'offer' => $this->whenLoaded('offer', fn () => $this->offer === null ? null : [
                'id' => $this->offer->id,
                'company_id' => $this->offer->company_id,
                'type' => $this->offer->type,
                'title' => $this->offer->title,
                'price' => $this->offer->price,
                'currency' => $this->offer->currency,
                'status' => $this->offer->status,
            ]),
        ];
    }
}
