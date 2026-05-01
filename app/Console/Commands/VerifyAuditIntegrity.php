<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditService;
use Illuminate\Console\Command;

class VerifyAuditIntegrity extends Command
{
    protected $signature = 'audit:verify-integrity
                            {--limit=10000 : Maximum number of rows to check (oldest-first)}';

    protected $description = 'Walk the audit_logs hash chain and report any tampered rows.';

    public function handle(AuditService $service): int
    {
        $limit = (int) $this->option('limit');
        $this->info("Verifying audit log chain integrity (limit={$limit})...");

        $corrupted = $service->verifyIntegrity($limit);

        if ($corrupted === []) {
            $this->info('✓ Chain intact. No tampered rows detected.');

            return self::SUCCESS;
        }

        $this->error(sprintf('✗ %d tampered row(s) detected:', count($corrupted)));
        foreach ($corrupted as $id) {
            $this->line('  - '.$id);
        }

        return self::FAILURE;
    }
}
