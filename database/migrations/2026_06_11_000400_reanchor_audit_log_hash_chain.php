<?php

use App\Services\Audit\AuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Roadmap 10.06 §2 — repair the audit-log hash chain.
 *
 * The db:backup / db:restore-drill crons used to raw-insert audit rows with
 * a bogus hash and a null previous pointer, so the integrity check reported
 * them as tampered ("✗ 6 tampered" on prod). The crons now write through
 * AuditService (real chain hashes); this migration re-anchors the existing
 * chain once so history verifies end-to-end again. Idempotent — a clean
 * chain rewrites nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rewritten = app(AuditService::class)->reanchorChain();
        Log::info('Audit hash chain re-anchored', ['rows_rewritten' => $rewritten]);
    }

    public function down(): void
    {
        // The old state was a broken chain — nothing to restore.
    }
};
