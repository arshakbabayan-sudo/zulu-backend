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
            'name' => $this->getTranslated('title', $lang, $this->name) ?? $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'legal_name' => $this->legal_name,
            'slug' => $this->slug,
            'tax_id' => $this->tax_id,
            'country' => $this->country,
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->phone,
            'website' => $this->website,
            'description' => $this->getTranslated('description', $lang) ?? $this->description,
            'logo' => $this->logo,
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
        ];
    }
}
