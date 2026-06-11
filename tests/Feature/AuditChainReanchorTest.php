<?php

namespace Tests\Feature;

use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Roadmap 10.06 §2 — audit hash chain: cron rows + repair.
 *
 * The backup cron used to raw-insert audit rows with a bogus hash, breaking
 * the chain. Now (a) system writers go through AuditService (labeled
 * actor_name_snapshot), and (b) reanchorChain() repairs a broken chain so
 * verifyIntegrity() comes back clean.
 */
class AuditChainReanchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_log_keeps_label_and_chain_verifies(): void
    {
        $service = app(AuditService::class);

        $service->log([
            'category' => 'system',
            'actor_type' => 'system',
            'actor_name_snapshot' => 'db:backup',
            'subject_type' => 'database',
            'subject_id' => 'zulu',
            'action' => 'database.backup.completed',
            'changes' => ['disk' => 'local', 'path' => 'x.sql.gz', 'size_bytes' => 1],
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_name_snapshot' => 'db:backup',
            'action' => 'database.backup.completed',
        ]);
        $this->assertSame([], $service->verifyIntegrity());
    }

    public function test_reanchor_repairs_a_chain_broken_by_raw_inserts(): void
    {
        $service = app(AuditService::class);

        $service->log(['category' => 'system', 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'a']);

        // Mimic the old cron's raw insert: bogus hash, null previous pointer.
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'category' => 'system',
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_name_snapshot' => 'db:backup',
            'subject_type' => 'database',
            'subject_id' => 'zulu',
            'action' => 'database.backup.completed',
            'changes' => json_encode(['disk' => 'local']),
            'context' => null,
            'hash' => hash('sha256', 'bogus'),
            'previous_log_hash' => null,
            'created_at' => now()->addSecond(),
        ]);

        // A legit row written AFTER the bad one (chains onto its stored hash).
        $this->travel(2)->seconds();
        $service->log(['category' => 'system', 'subject_type' => 'X', 'subject_id' => '2', 'action' => 'b']);

        $this->assertNotSame([], $service->verifyIntegrity(), 'the raw-inserted row must fail verification');

        $rewritten = $service->reanchorChain();

        $this->assertGreaterThan(0, $rewritten);
        $this->assertSame([], $service->verifyIntegrity(), 'chain must verify end-to-end after re-anchor');
        $this->assertSame(0, $service->reanchorChain(), 're-anchor must be idempotent');
    }
}
