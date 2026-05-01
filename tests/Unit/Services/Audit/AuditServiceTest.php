<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Contract;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_persists_row_with_all_required_fields(): void
    {
        $actor = User::factory()->create(['name' => 'Audit Actor']);
        $service = new AuditService;

        $log = $service->log([
            'category' => 'contract',
            'actor' => $actor,
            'subject_type' => 'Contract',
            'subject_id' => 'abc-123',
            'action' => 'signed',
            'changes' => ['status' => ['from' => 'sent', 'to' => 'signed_by_b']],
            'context' => ['party' => 'b'],
        ]);

        $this->assertNotNull($log);
        $this->assertSame('contract', $log->category);
        $this->assertSame('user', $log->actor_type);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('Audit Actor', $log->actor_name_snapshot);
        $this->assertSame('Contract', $log->subject_type);
        $this->assertSame('abc-123', $log->subject_id);
        $this->assertSame('signed', $log->action);
        $this->assertSame(64, strlen($log->hash));
    }

    public function test_log_with_null_actor_records_system_actor(): void
    {
        $log = (new AuditService)->log([
            'category' => 'system',
            'subject_type' => 'CronJob',
            'subject_id' => 'daily',
            'action' => 'ran',
        ]);

        $this->assertSame('system', $log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertSame('system', $log->actor_name_snapshot);
    }

    public function test_chain_of_hashes_links_each_log_to_predecessor(): void
    {
        $service = new AuditService;
        $actor = User::factory()->create();

        $a = $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'created']);
        $b = $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'updated']);
        $c = $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'deleted']);

        $this->assertNull($a->previous_log_hash);
        $this->assertSame($a->hash, $b->previous_log_hash);
        $this->assertSame($b->hash, $c->previous_log_hash);
    }

    public function test_verify_integrity_returns_empty_array_for_untampered_chain(): void
    {
        $service = new AuditService;
        $actor = User::factory()->create();

        $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'a']);
        $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '2', 'action' => 'b']);
        $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '3', 'action' => 'c']);

        $corrupted = $service->verifyIntegrity();

        $this->assertSame([], $corrupted);
    }

    public function test_verify_integrity_detects_tampered_log(): void
    {
        $service = new AuditService;
        $actor = User::factory()->create();

        $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'a']);
        $b = $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '2', 'action' => 'b']);
        $service->log(['category' => 'data_change', 'actor' => $actor, 'subject_type' => 'X', 'subject_id' => '3', 'action' => 'c']);

        // Mutate row B's action without recomputing its hash → tamper.
        DB::table('audit_logs')->where('id', $b->id)->update(['action' => 'b_tampered']);

        $corrupted = $service->verifyIntegrity();

        $this->assertContains($b->id, $corrupted);
    }

    public function test_log_model_change_captures_dirty_diff_on_updated(): void
    {
        $actor = User::factory()->create();
        $contract = $this->makeContractWithDirtyStatus();

        $log = (new AuditService)->logModelChange(
            'contract',
            'updated',
            $contract,
            $actor,
        );

        $this->assertNotNull($log);
        $this->assertSame('updated', $log->action);
        $this->assertArrayHasKey('before', $log->changes);
        $this->assertArrayHasKey('after', $log->changes);
        $this->assertContains('status', $log->changes['dirty_keys']);
    }

    private function makeContractWithDirtyStatus(): Contract
    {
        // Build a fake model state for the diff helper without hitting the DB.
        // (Contract has UUID PK; we don't need to persist it for this unit test.)
        $contract = new Contract;
        $contract->setRawAttributes([
            'id' => '00000000-0000-0000-0000-000000000001',
            'status' => 'draft',
        ], true);
        $contract->status = 'sent';

        return $contract;
    }
}
