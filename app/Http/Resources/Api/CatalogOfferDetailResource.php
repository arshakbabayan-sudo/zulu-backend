<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\AppliesPricingVisibility;
use App\Http\Resources\Api\Concerns\ResolvesApiLanguage;
use App\Http\Resources\Api\Concerns\SummarizesOfferModules;
use App\Models\Offer;
use App\Services\Offers\OfferNormalizationService;
use App\Services\Pricing\DisplayCurrencyService;
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
    use AppliesPricingVisibility;
    use ResolvesApiLanguage;
    use SummarizesOfferModules;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Public listing display uses the operator's CUSTOMER (brutto) markup
        // so the shown price equals the no-rule client charge (PricingResolver
        // fallback). Operator with NULL column → global default (today's 15%).
        $b2cPrice = app(PriceCalculatorService::class)->b2cPriceForOperator(
            $this->price ?? 0,
            $this->resource->company_id ?? null,
            'client'
        );
        // Phase 1 / B.3 — base_price visibility gated by trait. The
        // offer's owning operator id is `company_id` on Offer.
        $pricing = $this->safePricing(
            $request,
            $this->price,
            $this->currency,
            $this->resource->company_id ?? null
        );
        $lang = $this->apiLang($request);

        // Part A — additive top-level display siblings (mirror safePricing).
        // B4 — thread the offer's operator company id so an AMD display equals
        // the future seller-rate charge when a setting exists (agent null).
        $displayService = app(DisplayCurrencyService::class);
        $displayCurrency = $displayService->sanitize($request->query('display_currency'));
        $displayFields = $displayService->fieldsFor(
            $b2cPrice,
            $this->currency,
            $displayCurrency,
            $this->resource->company_id ?? null,
        );

        $data = [
            'id' => $this->id,
            'source_lang' => $this->resource->getAttribute('source_lang'),
            'translation_status' => $this->computeTranslationStatus($lang, ['title']),
            'type' => $this->type,
            'title' => $this->getTranslated('title', $lang) ?? $this->title,
            'price' => $b2cPrice,
            'currency' => $this->currency,
            'display_price' => $displayFields['display_price'],
            'display_currency' => $displayFields['display_currency'],
            'fx_rate' => $displayFields['fx_rate'],
            'fx_basis' => $displayFields['fx_basis'],
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

        $normalized = app(OfferNormalizationService::class)->normalize($this->resource, true, $lang, $displayCurrency);
        if ($normalized !== null) {
            $data['normalized_offer'] = $normalized;
        }

        return $data;
    }
}
