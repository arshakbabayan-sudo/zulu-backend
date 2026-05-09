<?php

namespace App\Services\Approvals;

use App\Models\Approval;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditService $auditService,
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
    public function approve(Approval $approval, User $actor, ?string $notes = null, ?string $bulkBatchId = null): Approval
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

        // Per-item audit log entry. For bulk operations $bulkBatchId is set
        // so all items in the same bulk-approve call share a correlation id.
        $this->auditService->log([
            'category' => 'approval',
            'action' => 'approved',
            'subject_type' => 'Approval',
            'subject_id' => (string) $approval->id,
            'actor' => $actor,
            'changes' => [
                'after' => [
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'approved_at' => (string) $approval->approved_at,
                    'decision_notes' => $notes,
                ],
            ],
            'context' => array_filter([
                'entity_type' => $approval->entity_type,
                'entity_id' => $approval->entity_id,
                'bulk_batch_id' => $bulkBatchId,
            ]),
        ]);

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

        $this->notifyRequester($approval, 'Your request has been rejected. Reason: '.($notes ?? 'No reason provided.'));

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
     * Bulk approve multiple pending approvals in one transaction.
     * Returns a per-id outcome array.
     *
     * @param  array<int>  $ids
     * @return array<int, array{id: int, ok: bool, message?: string}>
     */
    public function approveBulk(array $ids, User $actor, ?string $notes = null): array
    {
        // Correlate all per-item audit rows belonging to the same bulk action.
        $bulkBatchId = (string) Str::uuid();

        $results = [];
        foreach ($ids as $id) {
            $approval = Approval::query()->find($id);
            if ($approval === null) {
                $results[] = ['id' => (int) $id, 'ok' => false, 'message' => 'Not found'];

                continue;
            }
            try {
                $this->approve($approval, $actor, $notes, $bulkBatchId);
                $results[] = ['id' => (int) $id, 'ok' => true];
            } catch (\Throwable $e) {
                $results[] = ['id' => (int) $id, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Hook called from other domains (Contract / Connection / Refund / Package)
     * when an entity needs approval. Idempotent: if a pending approval row
     * already exists for the (entity_type, entity_id) tuple, returns it
     * unchanged. Otherwise creates one.
     *
     * @param  array{requested_by?: int|null, priority?: string|null, notes?: string|null}  $context
     */
    public function requestForEntity(string $entityType, int $entityId, array $context = []): Approval
    {
        $existing = Approval::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('status', ['pending', 'under_review'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'requested_by' => $context['requested_by'] ?? null,
            'status' => 'pending',
            'priority' => $context['priority'] ?? 'normal',
            'notes' => $context['notes'] ?? null,
        ]);
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
                'status' => 'unread',
            ]);
        }
    }
}
