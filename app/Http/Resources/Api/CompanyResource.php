<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\ResolvesApiLanguage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    use ResolvesApiLanguage;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lang = $this->apiLang($request);

        return [
            'id' => $this->id,
            'source_lang' => $this->resource->getAttribute('source_lang'),
            'translation_status' => $this->computeTranslationStatus($lang, ['description', 'address']),
            'name' => $this->name,
            'type' => $this->type,
            'legal_name' => $this->legal_name,
            'slug' => $this->slug,
            'tax_id' => $this->tax_id,
            'country' => $this->country,
            'city' => $this->city,
            'address' => $this->getTranslated('address', $lang) ?? $this->address,
            'phone' => $this->phone,
            'website' => $this->website,
            'description' => $this->getTranslated('description', $lang) ?? $this->description,
            'logo' => $this->logo,
            'is_partner_visible' => (bool) $this->is_partner_visible,
            'governance_status' => $this->governance_status,
            'is_seller' => (bool) $this->is_seller,
            'seller_activated_at' => $this->seller_activated_at?->toIso8601String(),
            'profile_completed' => (bool) $this->profile_completed,
            'active_seller_permissions_count' => $this->when(
                isset($this->active_seller_permissions_count),
                (int) $this->active_seller_permissions_count
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Phase 7.2 — admin archive markers (super-admin UI only)
            'archived_at' => $this->archived_at?->toIso8601String(),
            'archived_by_user_id' => $this->archived_by_user_id,
            'archived_reason' => $this->archived_reason,
            // P0-1 — Stripe Connect readiness (operational flags only; the
            // OAuth/access tokens stay in $hidden and never serialize). Feeds
            // the Companies list "Payments-ready" column + the detail Payments tab.
            'stripe_connect_id' => $this->stripe_connect_id,
            'stripe_charges_enabled' => (bool) $this->stripe_charges_enabled,
            'stripe_payouts_enabled' => (bool) $this->stripe_payouts_enabled,
            'stripe_details_submitted' => (bool) $this->stripe_details_submitted,
        ];
    }
}
