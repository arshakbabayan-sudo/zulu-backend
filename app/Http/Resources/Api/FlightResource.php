<?php

namespace App\Http\Resources\Api;

use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Flight
 */
class FlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = $this->resource->toOfferEmbedArray();

        $dual = null;
        if ($request->user() !== null) {
            $dual = app(PriceCalculatorService::class)->dualPrice((float) ($this->adult_price ?? 0));
        }

        $merged = array_merge([
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ], $base, [
            'cabins' => $this->whenLoaded('cabins', fn () => $this->resource->cabinsForApiResponse()),
            'offer' => $this->whenLoaded('offer', fn () => [
                'id' => $this->offer->id,
                'company_id' => $this->offer->company_id,
                'type' => $this->offer->type,
                'title' => $this->offer->title,
                'price' => $this->offer->price,
                'currency' => $this->offer->currency,
                'status' => $this->offer->status,
            ]),
            'company' => $this->whenLoaded('company', fn () => CompanyResource::make($this->company)->toArray($request)),
        ]);

        if ($dual !== null) {
            $merged['b2b_price'] = $dual['b2b_price'];
            $merged['b2c_price'] = $dual['b2c_price'];
        }

        return $merged;
    }
}
