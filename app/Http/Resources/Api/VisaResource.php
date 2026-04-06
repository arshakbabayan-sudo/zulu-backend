<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offer = $this->whenLoaded('offer', fn () => $this->offer);
        $offerPrice = $offer?->price ?? null;

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'company_id' => $offer?->company_id ?? null,
            'country' => $this->country,
            'visa_type' => $this->visa_type,
            'processing_days' => $this->processing_days,
            'name' => $this->name,
            'description' => $this->description,
            'required_documents' => self::requiredDocumentsAsArray($this->required_documents),
            'visa_price' => $this->price,
            'offer_price' => $offerPrice,
            'price' => $offerPrice,
            'currency' => $offer?->currency ?? null,
            'status' => $offer?->status ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<mixed>|null
     */
    private static function requiredDocumentsAsArray(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
