<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
