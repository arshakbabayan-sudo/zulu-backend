<?php

namespace App\Http\Resources\Api;

use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public catalog listing — no company_id; minimal fields for browse cards.
 *
 * @mixin \App\Models\Offer
 */
class CatalogOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $b2cPrice = app(PriceCalculatorService::class)->b2cPrice($this->price ?? 0);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'price' => $b2cPrice,
            'currency' => $this->currency,
        ];
    }
}
