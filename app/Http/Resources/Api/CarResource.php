<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\ResolvesApiLanguage;
use App\Models\Car;
use App\Services\Availability\AvailabilityNormalizerService;
use App\Services\Cars\CarAdvancedOptionsNormalizer;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator / inventory car detail — stable envelope aligned with other commerce modules.
 *
 * @mixin Car
 */
class CarResource extends JsonResource
{
    use ResolvesApiLanguage;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lang = $this->apiLang($request);
        $pricing = $this->base_price !== null
            ? app(PriceCalculatorService::class)->normalizedPrice($this->base_price, $this->offer?->currency)
            : null;

        $availability = app(AvailabilityNormalizerService::class)->normalize([
            'available_from' => $this->availability_window_start,
            'available_to' => $this->availability_window_end,
            'capacity' => $this->seats,
        ]);

        $advanced = app(CarAdvancedOptionsNormalizer::class)->forApi(
            is_array($this->advanced_options) ? $this->advanced_options : null
        );

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $this->whenLoaded('offer', fn () => $this->offer !== null ? (int) $this->offer->company_id : null),
            'pickup_location' => $this->pickup_location,
            'dropoff_location' => $this->dropoff_location,
            'vehicle_class' => $this->vehicle_class,
            'vehicle_type' => $this->vehicle_type,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year !== null ? (int) $this->year : null,
            'transmission_type' => $this->transmission_type,
            'fuel_type' => $this->fuel_type,
            'fleet' => $this->fleet,
            'category' => $this->category,
            'seats' => $this->seats !== null ? (int) $this->seats : null,
            'suitcases' => $this->suitcases !== null ? (int) $this->suitcases : null,
            'small_bag' => $this->small_bag !== null ? (int) $this->small_bag : null,
            'availability_window_start' => $this->availability_window_start?->toIso8601String(),
            'availability_window_end' => $this->availability_window_end?->toIso8601String(),
            'pricing_mode' => $this->pricing_mode,
            'base_price' => $this->base_price !== null ? (float) $this->base_price : null,
            'pricing' => $pricing,
            'status' => $this->status,
            'availability_status' => $this->availability_status,
            'availability' => $availability,
            'advanced_options' => $advanced,
            'visibility_rule' => $this->visibility_rule ?? 'show_all',
            'appears_in_web' => (bool) ($this->appears_in_web ?? true),
            'appears_in_admin' => (bool) ($this->appears_in_admin ?? true),
            'appears_in_zulu_admin' => (bool) ($this->appears_in_zulu_admin ?? true),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'offer' => $this->whenLoaded('offer', fn () => $this->offer === null ? null : [
                'id' => $this->offer->id,
                'company_id' => $this->offer->company_id,
                'type' => $this->offer->type,
                'title' => $this->offer->getTranslated('title', $lang) ?? $this->offer->title,
                'price' => $this->offer->price,
                'currency' => $this->offer->currency,
                'status' => $this->offer->status,
            ]),
        ];
    }
}
