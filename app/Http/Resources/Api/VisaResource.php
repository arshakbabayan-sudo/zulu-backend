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
        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'country' => $this->country,
            'visa_type' => $this->visa_type,
            'processing_days' => $this->processing_days,
        ];
    }
}
