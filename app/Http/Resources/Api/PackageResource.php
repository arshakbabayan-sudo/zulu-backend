<?php

namespace App\Http\Resources\Api;

use App\Services\Packages\PackageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator package payload with optional components and derived pricing.
 *
 * @mixin \App\Models\Package
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $componentsLoaded = $this->relationLoaded('components');

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $this->company_id,
            'package_type' => $this->package_type,
            'package_title' => $this->package_title,
            'package_subtitle' => $this->package_subtitle,
            'destination_country' => $this->destination_country,
            'destination_city' => $this->destination_city,
            'duration_days' => $this->duration_days,
            'min_nights' => $this->min_nights,
            'adults_count' => $this->adults_count,
            'children_count' => $this->children_count,
            'infants_count' => $this->infants_count,
            'base_price' => $this->base_price !== null ? (float) $this->base_price : null,
            'display_price_mode' => $this->display_price_mode,
            'currency' => $this->currency,
            'is_public' => (bool) $this->is_public,
            'is_bookable' => (bool) $this->is_bookable,
            'is_package_eligible' => (bool) $this->is_package_eligible,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'offer' => $this->whenLoaded('offer', function () {
                return [
                    'id' => $this->offer->id,
                    'title' => $this->offer->title,
                    'price' => $this->offer->price !== null ? (float) $this->offer->price : null,
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
            'components' => $this->when($componentsLoaded, function () {
                return $this->components->map(function ($c) {
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
                            'title' => $offer->title,
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
