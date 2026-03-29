<?php

namespace App\Services\Approvals;

use App\Models\Approval;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * @param  array{entity_type:string,entity_id:int,status?:string,approved_by?:int|null,approved_at?:string|null,requested_by?:int}  $data
     */
    public function create(array $data): Approval
    {
        if (! isset($data['status'])) {
            $data['status'] = 'pending';
        }

        return Approval::query()->create($data);
    }

    /**
     * Approve the request and notify the requester.
     */
    public function approve(Approval $approval, User $actor, ?string $notes = null): Approval
    {
        if (! in_array($approval->status, ['pending', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'approval' => 'Approval cannot be approved in its current state.',
            ]);
        }

        $approval->status = 'approved';
        $approval->approved_by = $actor->id;
        $approval->approved_at = Carbon::now();
        $approval->reviewed_at = Carbon::now();
        $approval->reviewed_by = $actor->id;
        $approval->notes = $notes;
        $approval->decision_notes = $notes;

        $approval->save();

        $this->notifyRequester($approval, 'Your request has been approved.');

        return $approval->fresh();
    }

    /**
     * Reject the request and notify the requester.
     */
    public function reject(Approval $approval, User $actor, ?string $notes = null): Approval
    {
        if (! in_array($approval->status, ['pending', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'approval' => 'Approval cannot be rejected in its current state.',
            ]);
        }

        $approval->status = 'rejected';
        $approval->reviewed_at = Carbon::now();
        $approval->reviewed_by = $actor->id;
        $approval->notes = $notes;
        $approval->decision_notes = $notes;

        $approval->save();

        $this->notifyRequester($approval, 'Your request has been rejected. Reason: ' . ($notes ?? 'No reason provided.'));

        return $approval->fresh();
    }

    /**
     * Set the status to under_review.
     */
    public function startReview(Approval $approval, int $reviewerId): Approval
    {
        $approval->status = 'under_review';
        $approval->reviewed_at = Carbon::now();
        $approval->reviewed_by = $reviewerId;
        $approval->save();

        return $approval->fresh();
    }

    /**
     * Send notification to the user who requested the approval.
     */
    private function notifyRequester(Approval $approval, string $message): void
    {
        if ($approval->requested_by) {
            $this->notificationService->create([
                'user_id' => $approval->requested_by,
                'type' => 'approval_update',
                'title' => 'Approval Status Updated',
                'message' => $message,
                'status' => 'unread'
            ]);
        }
    }
}
