<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commission_policy_id' => $this->commission_policy_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'company_id' => $this->company_id,
            'service_type' => $this->service_type,
            'commission_mode' => $this->commission_mode,
            'commission_value' => (float) $this->commission_value,
            'commission_amount_snapshot' => (float) $this->commission_amount_snapshot,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
