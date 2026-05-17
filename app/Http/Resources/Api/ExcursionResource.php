<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\DerivesLocationLabels;
use App\Http\Resources\Api\Concerns\ResolvesApiLanguage;
use App\Models\Excursion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Excursion
 */
class ExcursionResource extends JsonResource
{
    use DerivesLocationLabels;
    use ResolvesApiLanguage;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lang = $this->apiLang($request);
        $loc = $this->relationLoaded('location') ? $this->getRelation('location') : ($this->location_id ? $this->location()->first() : null);
        $labels = $this->deriveLocationLabels($loc);
        $locationLabel = $labels['city'] ?? $labels['region'] ?? $labels['country'];

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'source_lang' => $this->resource->getAttribute('source_lang'),
            'translation_status' => $this->computeTranslationStatus($lang, [
                'tour_name', 'overview', 'meeting_pickup', 'additional_info',
            ]),
            'company_id' => $this->whenLoaded('offer', fn () => $this->offer !== null ? (int) $this->offer->company_id : null),
            // Convenience mirrors of commercial fields (same values as nested `offer`) for operator/inventory tables.
            'title' => $this->whenLoaded('offer', fn () => $this->offer !== null ? ($this->offer->getTranslated('title', $lang) ?? $this->offer->title) : null),
            'price' => $this->whenLoaded('offer', fn () => $this->offer?->price !== null ? (float) $this->offer->price : null),
            'currency' => $this->whenLoaded('offer', fn () => $this->offer?->currency),
            'location' => $locationLabel,
            'location_id' => $this->location_id,
            'country' => $labels['country'],
            'region' => $labels['region'],
            'city' => $labels['city'],
            'general_category' => $this->general_category,
            'category' => $this->category,
            'excursion_type' => $this->excursion_type,
            'tour_name' => $this->getTranslated('tour_name', $lang) ?? $this->tour_name,
            'overview' => $this->getTranslated('overview', $lang) ?? $this->overview,
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
            'meeting_pickup' => $this->getTranslated('meeting_pickup', $lang) ?? $this->meeting_pickup,
            'additional_info' => $this->getTranslated('additional_info', $lang) ?? $this->additional_info,
            'cancellation_policy' => $this->getTranslated('cancellation_policy', $lang) ?? $this->cancellation_policy,
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
                'title' => $this->offer->getTranslated('title', $lang) ?? $this->offer->title,
                'price' => $this->offer->price,
                'currency' => $this->offer->currency,
                'status' => $this->offer->status,
            ]),
        ];
    }
}
