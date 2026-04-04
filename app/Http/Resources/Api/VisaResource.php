<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offer = $this->whenLoaded('offer', fn () => $this->offer);

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $offer?->company_id ?? null,
            'country' => $this->country,
            'visa_type' => $this->visa_type,
            'processing_days' => $this->processing_days,
            'price' => $offer?->price ?? null,
            'currency' => $offer?->currency ?? null,
            'status' => $offer?->status ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
