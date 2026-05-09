<?php

namespace Tests\Unit\Services\Approvals;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalService(
            app(NotificationService::class),
            app(AuditService::class),
        );
    }

    private function makeUser(array $attrs = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => 'user'.uniqid().'@example.com',
            'password' => 'hash',
            'status' => 'active',
        ], $attrs));
    }

    private function makeApproval(array $attrs = []): Approval
    {
        return Approval::query()->create(array_merge([
            'entity_type' => 'Contract',
            'entity_id' => 1,
            'status' => 'pending',
            'priority' => 'normal',
        ], $attrs));
    }

    public function test_create_defaults_status_to_pending(): void
    {
        $approval = $this->service->create([
            'entity_type' => 'Contract',
            'entity_id' => 5,
        ]);

        $this->assertSame('pending', $approval->status);
        $this->assertSame('Contract', $approval->entity_type);
        $this->assertSame(5, $approval->entity_id);
    }

    public function test_approve_marks_status_and_records_actor(): void
    {
        $actor = $this->makeUser();
        $approval = $this->makeApproval();

        $result = $this->service->approve($approval, $actor, 'looks good');

        $this->assertSame('approved', $result->status);
        $this->assertSame($actor->id, $result->approved_by);
        $this->assertSame($actor->id, $result->reviewed_by);
        $this->assertNotNull($result->approved_at);
        $this->assertNotNull($result->reviewed_at);
        $this->assertSame('looks good', $result->decision_notes);
    }

    public function test_approve_writes_audit_log_with_correct_subject(): void
    {
        $actor = $this->makeUser();
        $approval = $this->makeApproval();

        $this->service->approve($approval, $actor, 'ok');

        $log = AuditLog::query()
            ->where('category', 'approval')
            ->where('subject_type', 'Approval')
            ->where('subject_id', (string) $approval->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('approved', $log->action);
    }

    public function test_approve_notifies_requester_when_set(): void
    {
        $requester = $this->makeUser();
        $actor = $this->makeUser();
        $approval = $this->makeApproval(['requested_by' => $requester->id]);

        $this->service->approve($approval, $actor);

        $this->assertSame(1, Notification::query()->where('user_id', $requester->id)->count());
    }

    public function test_approve_skips_notification_when_no_requester(): void
    {
        $actor = $this->makeUser();
        $approval = $this->makeApproval(['requested_by' => null]);

        $this->service->approve($approval, $actor);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_approve_throws_when_already_approved(): void
    {
        $actor = $this->makeUser();
        $approval = $this->makeApproval(['status' => 'approved']);

        $this->expectException(ValidationException::class);
        $this->service->approve($approval, $actor);
    }

    public function test_approve_throws_when_rejected(): void
    {
        $actor = $this->makeUser();
        $approval = $this->makeApproval(['status' => 'rejected']);

        $this->expectException(ValidationException::class);
        $this->service->approve($approval, $actor);
    }

    public function test_approve_allowed_from_under_review(): void
    {
        $actor = $this->makeUser();
        $approval = $this->makeApproval(['status' => 'under_review']);

        $result = $this->service->approve($approval, $actor);

        $this->assertSame('approved', $result->status);
    }

    public function test_reject_marks_status_and_includes_reason_in_notification(): void
    {
        $requester = $this->makeUser();
        $actor = $this->makeUser();
        $approval = $this->makeApproval(['requested_by' => $requester->id]);

        $result = $this->service->reject($approval, $actor, 'missing fields');

        $this->assertSame('rejected', $result->status);
        $this->assertSame('missing fields', $result->decision_notes);

        $note = Notification::query()->where('user_id', $requester->id)->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('missing fields', (string) $note->message);
    }

    public function test_reject_throws_when_already_approved(): void
    {
        $actor = $this->makeUser();
        $approval = $this->makeApproval(['status' => 'approved']);

        $this->expectException(ValidationException::class);
        $this->service->reject($approval, $actor);
    }

    public function test_start_review_transitions_to_under_review(): void
    {
        $reviewer = $this->makeUser();
        $approval = $this->makeApproval();

        $result = $this->service->startReview($approval, $reviewer->id);

        $this->assertSame('under_review', $result->status);
        $this->assertSame($reviewer->id, $result->reviewed_by);
    }

    public function test_request_for_entity_is_idempotent(): void
    {
        $first = $this->service->requestForEntity('Contract', 99, ['priority' => 'high']);
        $second = $this->service->requestForEntity('Contract', 99, ['priority' => 'low']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('high', $second->priority); // first one wins
    }

    public function test_request_for_entity_creates_separate_rows_for_different_entities(): void
    {
        $a = $this->service->requestForEntity('Contract', 1);
        $b = $this->service->requestForEntity('Contract', 2);
        $c = $this->service->requestForEntity('Connection', 1);

        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->id, $c->id);
    }

    public function test_request_for_entity_ignores_resolved_approvals(): void
    {
        $resolved = $this->makeApproval(['entity_type' => 'Contract', 'entity_id' => 50, 'status' => 'approved']);

        $fresh = $this->service->requestForEntity('Contract', 50);

        $this->assertNotSame($resolved->id, $fresh->id);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_approve_bulk_returns_per_id_outcome(): void
    {
        $actor = $this->makeUser();
        $a1 = $this->makeApproval();
        $a2 = $this->makeApproval(['status' => 'approved']);
        $a3 = $this->makeApproval();

        $results = $this->service->approveBulk([$a1->id, $a2->id, $a3->id, 99999], $actor);

        $this->assertSame(4, count($results));
        $this->assertTrue($results[0]['ok']);
        $this->assertFalse($results[1]['ok']); // already approved
        $this->assertTrue($results[2]['ok']);
        $this->assertFalse($results[3]['ok']); // not found
        $this->assertSame('Not found', $results[3]['message']);
    }

    public function test_approve_bulk_correlates_audit_with_shared_batch_id(): void
    {
        $actor = $this->makeUser();
        $ids = [
            $this->makeApproval()->id,
            $this->makeApproval()->id,
            $this->makeApproval()->id,
        ];

        $this->service->approveBulk($ids, $actor);

        $batchIds = AuditLog::query()
            ->where('category', 'approval')
            ->get()
            ->pluck('context.bulk_batch_id')
            ->unique()
            ->filter()
            ->values();

        $this->assertSame(1, $batchIds->count(), 'all bulk items should share one batch_id');
    }
}
