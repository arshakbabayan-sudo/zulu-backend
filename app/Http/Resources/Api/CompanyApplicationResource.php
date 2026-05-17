<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CompanyApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $disk = Storage::disk('local');

        // The applicant user — only set on Phase-8 onwards applications where
        // the user registered first at /register/<role> and submitted the
        // app while authenticated. Older anonymous submissions stay null.
        $user = null;
        if ($this->user_id) {
            $userModel = $this->user;
            if ($userModel) {
                $user = [
                    'id' => (int) $userModel->id,
                    'name' => $userModel->name,
                    'email' => $userModel->email,
                    'intended_role' => $userModel->intended_role ?? null,
                ];
            } else {
                $user = ['id' => (int) $this->user_id];
            }
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $user,
            'company_name' => $this->company_name,
            'company_type' => $this->company_type,
            'business_email' => $this->business_email,
            'legal_address' => $this->legal_address,
            'actual_address' => $this->actual_address,
            'country' => $this->country,
            'city' => $this->city,
            'phone' => $this->phone,
            'tax_id' => $this->tax_id,
            'contact_person' => $this->contact_person,
            'position' => $this->position,
            'state_certificate_path' => $this->state_certificate_path !== null && $this->state_certificate_path !== ''
                ? $disk->exists($this->state_certificate_path)
                : false,
            'license_path' => $this->license_path !== null && $this->license_path !== ''
                ? $disk->exists($this->license_path)
                : false,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewer' => $this->whenLoaded('reviewer', function () {
                if ($this->reviewer === null) {
                    return null;
                }

                return [
                    'id' => $this->reviewer->id,
                    'name' => $this->reviewer->name,
                ];
            }),
        ];
    }
}
