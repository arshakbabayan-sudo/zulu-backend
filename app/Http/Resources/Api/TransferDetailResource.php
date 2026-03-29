<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator transfer show — full read shape (no offer price mutation).
 *
 * @mixin \App\Models\Transfer
 */
class TransferDetailResource extends JsonResource
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
            'transfer_title' => $this->transfer_title,
            'transfer_type' => $this->transfer_type,
            'pickup_country' => $this->pickup_country,
            'pickup_city' => $this->pickup_city,
            'pickup_point_type' => $this->pickup_point_type,
            'pickup_point_name' => $this->pickup_point_name,
            'dropoff_country' => $this->dropoff_country,
            'dropoff_city' => $this->dropoff_city,
            'dropoff_point_type' => $this->dropoff_point_type,
            'dropoff_point_name' => $this->dropoff_point_name,
            'pickup_latitude' => $this->pickup_latitude !== null ? (float) $this->pickup_latitude : null,
            'pickup_longitude' => $this->pickup_longitude !== null ? (float) $this->pickup_longitude : null,
            'dropoff_latitude' => $this->dropoff_latitude !== null ? (float) $this->dropoff_latitude : null,
            'dropoff_longitude' => $this->dropoff_longitude !== null ? (float) $this->dropoff_longitude : null,
            'route_distance_km' => $this->route_distance_km !== null ? (float) $this->route_distance_km : null,
            'route_label' => $this->route_label,
            'service_date' => $this->service_date?->format('Y-m-d'),
            'pickup_time' => $this->formatTimeValue($this->pickup_time),
            'estimated_duration_minutes' => (int) $this->estimated_duration_minutes,
            'availability_window_start' => $this->availability_window_start?->toIso8601String(),
            'availability_window_end' => $this->availability_window_end?->toIso8601String(),
            'vehicle_category' => $this->vehicle_category,
            'vehicle_class' => $this->vehicle_class,
            'private_or_shared' => $this->private_or_shared,
            'passenger_capacity' => (int) $this->passenger_capacity,
            'luggage_capacity' => (int) $this->luggage_capacity,
            'child_seat_available' => (bool) $this->child_seat_available,
            'accessibility_support' => (bool) $this->accessibility_support,
            'minimum_passengers' => (int) $this->minimum_passengers,
            'maximum_passengers' => (int) $this->maximum_passengers,
            'maximum_luggage' => $this->maximum_luggage !== null ? (int) $this->maximum_luggage : null,
            'child_seat_required_rule' => $this->child_seat_required_rule,
            'special_assistance_supported' => (bool) $this->special_assistance_supported,
            'pricing_mode' => $this->pricing_mode,
            'base_price' => $this->base_price !== null ? (float) $this->base_price : null,
            'free_cancellation' => (bool) $this->free_cancellation,
            'cancellation_policy_type' => $this->cancellation_policy_type,
            'cancellation_deadline_at' => $this->cancellation_deadline_at?->toIso8601String(),
            'availability_status' => $this->availability_status,
            'bookable' => (bool) $this->bookable,
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
        ];
    }

    private function formatTimeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return (string) $value;
    }
}
