<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'status' => $this->status,
            'total_price' => $this->total_price,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'offer_id' => $item->offer_id,
                    'price' => $item->price,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ])->values()->all();
            }),
            'passengers' => $this->whenLoaded('passengers', function () {
                return $this->passengers->map(fn ($p) => [
                    'id' => $p->id,
                    'first_name' => $p->first_name,
                    'last_name' => $p->last_name,
                    'passport_number' => $p->passport_number,
                    'passport_expiry' => $p->passport_expiry?->format('Y-m-d'),
                    'nationality' => $p->nationality,
                    'date_of_birth' => $p->date_of_birth?->format('Y-m-d'),
                    'gender' => $p->gender,
                    'passenger_type' => $p->passenger_type,
                    'email' => $p->email,
                    'phone' => $p->phone,
                    'seat_number' => $p->pivot->seat_number ?? null,
                    'booking_item_id' => $p->pivot->booking_item_id ?? null,
                    'special_requests' => $p->pivot->special_requests ?? null,
                ])->values()->all();
            }),
        ];
    }
}
