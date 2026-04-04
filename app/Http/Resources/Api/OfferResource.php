<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\SummarizesOfferModules;
use App\Services\Offers\OfferNormalizationService;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator-facing offer JSON. Top-level pricing reflects operator rules; module keys are summary-only
 * (see {@see SummarizesOfferModules}). Full module detail is only available from module API endpoints — never
 * re-expand nested module data into this resource.
 */
class OfferResource extends JsonResource
{
    use SummarizesOfferModules;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dual = app(PriceCalculatorService::class)->dualPrice($this->price ?? 0);
        $pricing = app(PriceCalculatorService::class)->normalizedPrice($this->price, $this->currency);

        $data = [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'type' => $this->type,
            'title' => $this->title,
            'price' => $dual['b2b_price'],
            'b2b_price' => $dual['b2b_price'],
            'b2c_price' => $dual['b2c_price'],
            'currency' => $this->currency,
            'pricing' => $pricing,
            'status' => $this->status,
            'flight' => $this->when(
                $this->relationLoaded('flight'),
                fn () => $this->flight ? $this->flightModuleSummary() : null
            ),
            'hotel' => $this->when(
                $this->relationLoaded('hotel'),
                fn () => $this->hotel ? $this->hotelModuleSummary() : null
            ),
            'transfer' => $this->when(
                $this->relationLoaded('transfer'),
                fn () => $this->transfer ? $this->transferModuleSummary() : null
            ),
            'car' => $this->when(
                $this->relationLoaded('car'),
                fn () => $this->car ? $this->carModuleSummary() : null
            ),
            'excursion' => $this->when(
                $this->relationLoaded('excursion'),
                fn () => $this->excursion ? $this->excursionModuleSummary() : null
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

        $normalized = app(OfferNormalizationService::class)->normalize($this->resource);
        if ($normalized !== null) {
            $data['normalized_offer'] = $normalized;
        }

        return $data;
    }
}
