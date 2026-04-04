<?php

namespace App\Http\Resources\Api;

use App\Models\Excursion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Excursion
 */
class ExcursionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $this->whenLoaded('offer', fn () => $this->offer !== null ? (int) $this->offer->company_id : null),
            // Convenience mirrors of commercial fields (same values as nested `offer`) for operator/inventory tables.
            'title' => $this->whenLoaded('offer', fn () => $this->offer?->title),
            'price' => $this->whenLoaded('offer', fn () => $this->offer?->price !== null ? (float) $this->offer->price : null),
            'currency' => $this->whenLoaded('offer', fn () => $this->offer?->currency),
            'location' => $this->location,
            'country' => $this->country,
            'city' => $this->city,
            'general_category' => $this->general_category,
            'category' => $this->category,
            'excursion_type' => $this->excursion_type,
            'tour_name' => $this->tour_name,
            'overview' => $this->overview,
            'duration' => $this->duration,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'language' => $this->language,
            'group_size' => (int) $this->group_size,
            'ticket_max_count' => $this->ticket_max_count !== null ? (int) $this->ticket_max_count : null,
            'status' => $this->status,
            'is_available' => (bool) $this->is_available,
            'is_bookable' => (bool) $this->is_bookable,
            'includes' => $this->includes,
            'meeting_pickup' => $this->meeting_pickup,
            'additional_info' => $this->additional_info,
            'cancellation_policy' => $this->cancellation_policy,
            'photos' => $this->photos,
            'price_by_dates' => $this->price_by_dates,
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
                'title' => $this->offer->title,
                'price' => $this->offer->price,
                'currency' => $this->offer->currency,
                'status' => $this->offer->status,
            ]),
        ];
    }
}
