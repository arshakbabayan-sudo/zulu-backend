<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PackageOrder
 */
class PackageOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'package_id' => $this->package_id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'order_number' => $this->order_number,
            'booking_channel' => $this->booking_channel,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'adults_count' => (int) $this->adults_count,
            'children_count' => (int) $this->children_count,
            'infants_count' => (int) $this->infants_count,
            'currency' => $this->currency,
            'base_component_total_snapshot' => (float) $this->base_component_total_snapshot,
            'discount_snapshot' => (float) $this->discount_snapshot,
            'markup_snapshot' => (float) $this->markup_snapshot,
            'addon_total_snapshot' => (float) $this->addon_total_snapshot,
            'final_total_snapshot' => (float) $this->final_total_snapshot,
            'display_price_mode_snapshot' => $this->display_price_mode_snapshot,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    $row = [
                        'id' => $item->id,
                        'package_component_id' => $item->package_component_id,
                        'offer_id' => $item->offer_id,
                        'module_type' => $item->module_type,
                        'package_role' => $item->package_role,
                        'is_required' => (bool) $item->is_required,
                        'price_snapshot' => (float) $item->price_snapshot,
                        'currency_snapshot' => $item->currency_snapshot,
                        'status' => $item->status,
                        'failure_reason' => $item->failure_reason,
                        'sort_order' => (int) $item->sort_order,
                    ];

                    if ($item->relationLoaded('offer') && $item->offer !== null) {
                        $row['offer'] = [
                            'id' => $item->offer->id,
                            'title' => $item->offer->title,
                            'type' => $item->offer->type,
                            'price' => $item->offer->price !== null ? (float) $item->offer->price : null,
                            'currency' => $item->offer->currency,
                        ];
                    }

                    if ($item->relationLoaded('company') && $item->company !== null) {
                        $row['company'] = [
                            'id' => $item->company->id,
                            'name' => $item->company->name,
                        ];
                    }

                    return $row;
                })->values()->all();
            }),
            'package' => $this->whenLoaded('package', function () {
                return [
                    'id' => $this->package->id,
                    'package_type' => $this->package->package_type,
                    'package_title' => $this->package->package_title,
                    'destination_city' => $this->package->destination_city,
                    'destination_country' => $this->package->destination_country,
                    'duration_days' => $this->package->duration_days,
                    'status' => $this->package->status,
                ];
            }),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            'company' => $this->whenLoaded('company', function () {
                return [
                    'id' => $this->company->id,
                    'name' => $this->company->name,
                    'slug' => $this->company->slug,
                ];
            }),
        ];
    }
}
