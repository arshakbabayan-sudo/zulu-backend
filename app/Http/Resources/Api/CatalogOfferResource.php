<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\AppliesPricingVisibility;
use App\Http\Resources\Api\Concerns\ResolvesApiLanguage;
use App\Models\Offer;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public catalog listing — no company_id; minimal fields for browse cards.
 *
 * @mixin Offer
 */
class CatalogOfferResource extends JsonResource
{
    use AppliesPricingVisibility;
    use ResolvesApiLanguage;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $b2cPrice = app(PriceCalculatorService::class)->b2cPrice($this->price ?? 0);
        // Phase 1 / B.3 — base_price visibility gated by trait.
        $pricing = $this->safePricing(
            $request,
            $this->price,
            $this->currency,
            $this->resource->company_id ?? null
        );
        $lang = $this->apiLang($request);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->getTranslated('title', $lang) ?? $this->title,
            'price' => $b2cPrice,
            'currency' => $this->currency,
            'pricing' => $pricing,
        ];
    }
}
