<?php

namespace App\Console\Commands;

use App\Models\AdminCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job — marks cases whose SLA window has elapsed as escalated.
 *
 * Targets: status in (open, in_progress, pending_customer)
 *          AND sla_due_at < now()
 *          AND escalated_at IS NULL
 *
 * Updates `escalated_at = now()` and `status = escalated`. Future
 * iterations can trigger Telegram / email notifications to the case
 * assignee from this same command.
 */
class EscalateOverdueCases extends Command
{
    protected $signature = 'cases:escalate-overdue {--dry-run : Report without writing}';

    protected $description = 'Mark cases whose SLA window has elapsed as escalated';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = AdminCase::query()
            ->whereIn('status', ['open', 'in_progress', 'pending_customer'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereNull('escalated_at');

        $count = $query->count();
        if ($count === 0) {
            $this->info('No overdue cases.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Escalating {$count} case(s)...");

        if ($dryRun) {
            $query->limit(20)->get(['id', 'case_number', 'priority', 'sla_due_at'])
                ->each(function ($c): void {
                    $this->line("- #{$c->id} {$c->case_number} priority={$c->priority} due={$c->sla_due_at}");
                });

            return self::SUCCESS;
        }

        $now = now();
        $affected = $query->update([
            'status' => 'escalated',
            'escalated_at' => $now,
            'updated_at' => $now,
        ]);

        Log::info('cases:escalate-overdue', ['escalated' => $affected]);
        $this->info("Escalated {$affected} case(s).");

        return self::SUCCESS;
    }
}
