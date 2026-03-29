<?php

namespace App\Http\Resources\Api;

use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator hotel index row — summary + offer + derived pricing hint.
 *
 * @mixin \App\Models\Hotel
 */
class HotelListResource extends JsonResource
{
    /**
     * Minimum price among active {@see HotelRoomPricing} rows for this hotel.
     *
     * @return array{starting_price: float|null, currency: string|null}
     */
    public static function minimumActivePricing(Hotel $hotel): array
    {
        $min = null;
        $currency = null;
        foreach ($hotel->rooms as $room) {
            foreach ($room->pricings as $pricing) {
                if ($pricing->status !== 'active') {
                    continue;
                }
                $p = (float) $pricing->price;
                if ($min === null || $p < $min) {
                    $min = $p;
                    $currency = $pricing->currency;
                }
            }
        }

        return [
            'starting_price' => $min !== null ? round($min, 2) : null,
            'currency' => $currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pricing = self::minimumActivePricing($this->resource);

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $this->company_id,
            'hotel_name' => $this->hotel_name,
            'property_type' => $this->property_type,
            'hotel_type' => $this->hotel_type,
            'star_rating' => $this->star_rating,
            'country' => $this->country,
            'region_or_state' => $this->region_or_state,
            'city' => $this->city,
            'district_or_area' => $this->district_or_area,
            'full_address' => $this->full_address,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'short_description' => $this->short_description,
            'main_image' => $this->main_image,
            'meal_type' => $this->meal_type,
            'review_score' => $this->review_score !== null ? (float) $this->review_score : null,
            'review_count' => (int) $this->review_count,
            'review_label' => $this->review_label,
            'availability_status' => $this->availability_status,
            'bookable' => (bool) $this->bookable,
            'is_package_eligible' => (bool) $this->is_package_eligible,
            'status' => $this->status,
            'rooms_count' => $this->whenLoaded('rooms', fn () => $this->rooms->count()),
            'starting_price' => $pricing['starting_price'],
            'currency' => $pricing['currency'],
            'offer' => $this->whenLoaded('offer', fn () => [
                'id' => $this->offer->id,
                'company_id' => $this->offer->company_id,
                'type' => $this->offer->type,
                'title' => $this->offer->title,
                'price' => $this->offer->price,
                'currency' => $this->offer->currency,
                'status' => $this->offer->status,
            ]),
        ];
    }
}
