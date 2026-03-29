<?php

namespace App\Http\Resources\Api;

use App\Services\Offers\OfferNormalizationService;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dual = app(PriceCalculatorService::class)->dualPrice($this->price ?? 0);

        $data = [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'type' => $this->type,
            'title' => $this->title,
            'price' => $dual['b2b_price'],
            'b2b_price' => $dual['b2b_price'],
            'b2c_price' => $dual['b2c_price'],
            'currency' => $this->currency,
            'status' => $this->status,
            'flight' => $this->when(
                $this->relationLoaded('flight'),
                fn () => $this->flight ? array_merge(
                    $this->flight->toOfferEmbedArray(),
                    $this->flight->relationLoaded('cabins')
                        ? ['cabins' => $this->flight->cabinsForApiResponse()]
                        : []
                ) : null
            ),
            'hotel' => $this->when(
                $this->relationLoaded('hotel'),
                fn () => $this->hotel ? $this->hotel->toOfferEmbedArray() : null
            ),
            'transfer' => $this->when(
                $this->relationLoaded('transfer'),
                fn () => $this->transfer ? $this->transfer->toOfferEmbedArray() : null
            ),
            'car' => $this->when(
                $this->relationLoaded('car'),
                fn () => $this->car ? [
                    'pickup_location' => $this->car->pickup_location,
                    'dropoff_location' => $this->car->dropoff_location,
                    'vehicle_class' => $this->car->vehicle_class,
                ] : null
            ),
            'excursion' => $this->when(
                $this->relationLoaded('excursion'),
                fn () => $this->excursion ? [
                    'location' => $this->excursion->location,
                    'duration' => $this->excursion->duration,
                    'group_size' => $this->excursion->group_size,
                ] : null
            ),
            'package' => $this->when(
                $this->relationLoaded('package'),
                fn () => $this->package ? [
                    'destination' => $this->package->destination,
                    'duration_days' => $this->package->duration_days,
                    'package_type' => $this->package->package_type,
                ] : null
            ),
            'visa' => $this->when(
                $this->relationLoaded('visa'),
                fn () => $this->visa ? [
                    'country' => $this->visa->country,
                    'visa_type' => $this->visa->visa_type,
                    'processing_days' => $this->visa->processing_days,
                ] : null
            ),
        ];

        $normalized = app(OfferNormalizationService::class)->normalize($this->resource);
        if ($normalized !== null) {
            $data['normalized_offer'] = $normalized;
        }

        return $data;
    }
}
