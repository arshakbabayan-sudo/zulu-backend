<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\AppliesPricingVisibility;
use App\Http\Resources\Api\Concerns\ResolvesApiLanguage;
use App\Models\Package;
use App\Services\Availability\AvailabilityNormalizerService;
use App\Services\Packages\PackageService;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator package payload with optional components and derived pricing.
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    use AppliesPricingVisibility;
    use ResolvesApiLanguage;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lang = $this->apiLang($request);
        $componentsLoaded = $this->relationLoaded('components');
        // Phase 1 / B.3 — base_price visibility gated by trait. Package
        // belongs to a company directly (Package.company_id).
        $ownerId = $this->resource->company_id ?? null;
        $pricing = $this->safePricing($request, $this->base_price, $this->currency, $ownerId);
        $availability = app(AvailabilityNormalizerService::class)->normalize([
            'capacity' => $this->adults_count,
        ]);

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $this->company_id,
            'source_lang' => $this->resource->getAttribute('source_lang'),
            'translation_status' => $this->computeTranslationStatus($lang, ['package_title', 'package_subtitle']),
            'package_type' => $this->package_type,
            'package_title' => $this->getTranslated('package_title', $lang) ?? $this->package_title,
            'package_subtitle' => $this->getTranslated('package_subtitle', $lang) ?? $this->package_subtitle,
            'destination_country' => $this->destination_country,
            'destination_city' => $this->destination_city,
            'duration_days' => $this->duration_days,
            'min_nights' => $this->min_nights,
            'adults_count' => $this->adults_count,
            'children_count' => $this->children_count,
            'infants_count' => $this->infants_count,
            'base_price' => $this->safeBasePrice($request, $this->base_price, $ownerId),
            'display_price_mode' => $this->display_price_mode,
            'currency' => $this->currency,
            'pricing' => $pricing,
            'availability' => $availability,
            'is_public' => (bool) $this->is_public,
            'is_bookable' => (bool) $this->is_bookable,
            'is_package_eligible' => (bool) $this->is_package_eligible,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'offer' => $this->whenLoaded('offer', function () use ($lang) {
                return [
                    'id' => $this->offer->id,
                    'title' => $this->offer->getTranslated('title', $lang) ?? $this->offer->title,
                    // Phase 1 / B.3 — nested offer.price was leaking supplier net to unauth.
                    // Use the trait's safeBasePrice() with the offer's own company_id.
                    'price' => $this->safeBasePrice($request, $this->offer->price, $this->offer->company_id ?? null),
                    'currency' => $this->offer->currency,
                    'status' => $this->offer->status,
                ];
            }),
            'company' => $this->whenLoaded('company', function () {
                return [
                    'id' => $this->company->id,
                    'name' => $this->company->name,
                    'slug' => $this->company->slug,
                ];
            }),
            'components' => $this->when($componentsLoaded, function () use ($lang) {
                return $this->components->map(function ($c) use ($lang) {
                    $offer = $c->relationLoaded('offer') ? $c->offer : null;

                    return [
                        'id' => $c->id,
                        'offer_id' => $c->offer_id,
                        'module_type' => $c->module_type,
                        'package_role' => $c->package_role,
                        'is_required' => (bool) $c->is_required,
                        'sort_order' => (int) $c->sort_order,
                        'selection_mode' => $c->selection_mode,
                        'price_override' => $c->price_override !== null ? (float) $c->price_override : null,
                        'offer' => $offer ? [
                            'id' => $offer->id,
                            'title' => $offer->getTranslated('title', $lang) ?? $offer->title,
                            'price' => $offer->price !== null ? (float) $offer->price : null,
                            'currency' => $offer->currency,
                            'status' => $offer->status,
                        ] : null,
                    ];
                })->values()->all();
            }),
            'calculated_base_price' => $this->when($componentsLoaded, function () {
                return app(PackageService::class)->calculateBasePrice($this->resource);
            }),
        ];
    }
}
