<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Excursion
 */
class ExcursionResource extends JsonResource
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
            'location' => $this->location,
            'duration' => $this->duration,
            'group_size' => (int) $this->group_size,
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
