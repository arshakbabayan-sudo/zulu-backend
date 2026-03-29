<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator hotel show — full module shape with rooms and pricings.
 *
 * @mixin \App\Models\Hotel
 */
class HotelDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'check_in_time' => $this->check_in_time,
            'check_out_time' => $this->check_out_time,
            'meal_type' => $this->meal_type,
            'free_wifi' => (bool) $this->free_wifi,
            'parking' => (bool) $this->parking,
            'airport_shuttle' => (bool) $this->airport_shuttle,
            'indoor_pool' => (bool) $this->indoor_pool,
            'outdoor_pool' => (bool) $this->outdoor_pool,
            'room_service' => (bool) $this->room_service,
            'front_desk_24h' => (bool) $this->front_desk_24h,
            'child_friendly' => (bool) $this->child_friendly,
            'accessibility_support' => (bool) $this->accessibility_support,
            'pets_allowed' => (bool) $this->pets_allowed,
            'free_cancellation' => (bool) $this->free_cancellation,
            'cancellation_policy_type' => $this->cancellation_policy_type,
            'cancellation_deadline_at' => $this->cancellation_deadline_at?->toIso8601String(),
            'prepayment_required' => (bool) $this->prepayment_required,
            'no_show_policy' => $this->no_show_policy,
            'review_score' => $this->review_score !== null ? (float) $this->review_score : null,
            'review_count' => (int) $this->review_count,
            'review_label' => $this->review_label,
            'availability_status' => $this->availability_status,
            'bookable' => (bool) $this->bookable,
            'room_inventory_mode' => $this->room_inventory_mode,
            'is_package_eligible' => (bool) $this->is_package_eligible,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'offer' => $this->whenLoaded('offer', fn () => [
                'id' => $this->offer->id,
                'company_id' => $this->offer->company_id,
                'type' => $this->offer->type,
                'title' => $this->offer->title,
                'price' => $this->offer->price,
                'currency' => $this->offer->currency,
                'status' => $this->offer->status,
            ]),
            'rooms' => $this->whenLoaded('rooms', function () {
                return $this->rooms->map(function ($room) {
                    return [
                        'id' => $room->id,
                        'room_type' => $room->room_type,
                        'room_name' => $room->room_name,
                        'max_adults' => (int) $room->max_adults,
                        'max_children' => (int) $room->max_children,
                        'max_total_guests' => (int) $room->max_total_guests,
                        'bed_type' => $room->bed_type,
                        'bed_count' => (int) $room->bed_count,
                        'room_size' => $room->room_size,
                        'room_view' => $room->room_view,
                        'private_bathroom' => (bool) $room->private_bathroom,
                        'smoking_allowed' => (bool) $room->smoking_allowed,
                        'air_conditioning' => (bool) $room->air_conditioning,
                        'wifi' => (bool) $room->wifi,
                        'tv' => (bool) $room->tv,
                        'mini_fridge' => (bool) $room->mini_fridge,
                        'tea_coffee_maker' => (bool) $room->tea_coffee_maker,
                        'kettle' => (bool) $room->kettle,
                        'washing_machine' => (bool) $room->washing_machine,
                        'soundproofing' => (bool) $room->soundproofing,
                        'terrace_or_balcony' => (bool) $room->terrace_or_balcony,
                        'patio' => (bool) $room->patio,
                        'bath' => (bool) $room->bath,
                        'shower' => (bool) $room->shower,
                        'view_type' => $room->view_type,
                        'room_images' => $room->room_images,
                        'room_inventory_count' => $room->room_inventory_count !== null ? (int) $room->room_inventory_count : null,
                        'status' => $room->status,
                        'pricings' => $room->relationLoaded('pricings')
                            ? $room->pricings->map(fn ($p) => [
                                'id' => $p->id,
                                'price' => (float) $p->price,
                                'currency' => $p->currency,
                                'pricing_mode' => $p->pricing_mode,
                                'valid_from' => $p->valid_from?->format('Y-m-d'),
                                'valid_to' => $p->valid_to?->format('Y-m-d'),
                                'min_nights' => $p->min_nights !== null ? (int) $p->min_nights : null,
                                'status' => $p->status,
                            ])->values()->all()
                            : [],
                    ];
                })->values()->all();
            }),
        ];
    }
}
