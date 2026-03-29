<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Approval
 */
class ApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'status' => $this->status,
            'notes' => $this->notes,
            'decision_notes' => $this->decision_notes,
            'priority' => $this->priority,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'requested_by' => $this->whenLoaded('requestedBy', function () {
                return [
                    'id' => $this->requestedBy->id,
                    'name' => $this->requestedBy->name,
                    'email' => $this->requestedBy->email,
                ];
            }),
            'approved_by_user' => $this->whenLoaded('approver', function () {
                return [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name,
                    'email' => $this->approver->email,
                ];
            }),
            'reviewed_by_user' => $this->whenLoaded('reviewedBy', function () {
                return [
                    'id' => $this->reviewedBy->id,
                    'name' => $this->reviewedBy->name,
                    'email' => $this->reviewedBy->email,
                ];
            }),
        ];
    }
}
