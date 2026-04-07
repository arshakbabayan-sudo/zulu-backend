<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\ResolvesApiLanguage;
use App\Http\Resources\Api\Concerns\SummarizesOfferModules;
use App\Models\Offer;
use App\Services\Offers\OfferNormalizationService;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public catalog offer detail. Core fields differ from operator where required (e.g. no company_id/status, B2C price);
 * module keys must stay summary-only and match operator {@see OfferResource} via {@see SummarizesOfferModules} — flat
 * scalars only, no nested module children or module pricing breakdowns. Full detail only from module endpoints.
 *
 * @mixin Offer
 */
class CatalogOfferDetailResource extends JsonResource
{
    use ResolvesApiLanguage;
    use SummarizesOfferModules;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $b2cPrice = app(PriceCalculatorService::class)->b2cPrice($this->price ?? 0);
        $pricing = app(PriceCalculatorService::class)->normalizedPrice($this->price, $this->currency);
        $lang = $this->apiLang($request);

        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->getTranslated('title', $lang) ?? $this->title,
            'price' => $b2cPrice,
            'currency' => $this->currency,
            'pricing' => $pricing,
            'flight' => $this->when(
                $this->relationLoaded('flight'),
                fn () => $this->flight ? $this->flightModuleSummary() : null
            ),
            'hotel' => $this->when(
                $this->relationLoaded('hotel'),
                fn () => $this->hotel ? $this->hotelModuleSummary($lang) : null
            ),
            'transfer' => $this->when(
                $this->relationLoaded('transfer'),
                fn () => $this->transfer ? $this->transferModuleSummary($lang) : null
            ),
            'car' => $this->when(
                $this->relationLoaded('car'),
                fn () => $this->car ? $this->carModuleSummary() : null
            ),
            'excursion' => $this->when(
                $this->relationLoaded('excursion'),
                fn () => $this->excursion ? $this->excursionModuleSummary($lang) : null
            ),
            'package' => $this->when(
                $this->relationLoaded('package'),
                fn () => $this->package ? $this->packageModuleSummary() : null
            ),
            'visa' => $this->when(
                $this->relationLoaded('visa'),
                fn () => $this->visa ? $this->visaModuleSummary() : null
            ),
        ];

        $normalized = app(OfferNormalizationService::class)->normalize($this->resource, true, $lang);
        if ($normalized !== null) {
            $data['normalized_offer'] = $normalized;
        }

        return $data;
    }
}
