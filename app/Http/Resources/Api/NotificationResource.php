<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'status' => $this->status,
            'event_type' => $this->event_type,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'related_company_id' => $this->related_company_id,
            'priority' => $this->priority,
            'created_at' => $this->created_at,
        ];
    }
}
