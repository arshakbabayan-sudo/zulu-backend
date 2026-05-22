<?php

namespace App\Console\Commands;

use App\Models\AdminCase;
use App\Models\User;
use App\Services\Notifications\NotificationService;
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

        // Fetch rows BEFORE the bulk update so we can notify assignees with
        // the actual case context (case_number + title) without an extra
        // round-trip per row.
        $affectedRows = $query->get(['id', 'case_number', 'title', 'priority', 'assigned_to_user_id', 'sla_due_at']);

        $now = now();
        $affected = $query->update([
            'status' => 'escalated',
            'escalated_at' => $now,
            'updated_at' => $now,
        ]);

        // A2 — notify each case's assignee that their case has been escalated.
        // Failure to notify is logged but doesn't roll back the escalation.
        $service = app(NotificationService::class);
        foreach ($affectedRows as $row) {
            $recipients = [];
            if ($row->assigned_to_user_id) {
                $recipients[] = (int) $row->assigned_to_user_id;
            }
            // Also fan out to all super-admins so an unassigned-but-escalated
            // case doesn't fall through the cracks. Cheap query — handful of
            // accounts on this platform.
            $superAdminIds = User::query()
                ->whereHas('memberships.role', function ($q): void {
                    $q->whereIn('name', ['super_admin', 'platform_admin']);
                })
                ->pluck('id')
                ->all();
            $recipients = array_unique(array_merge($recipients, $superAdminIds));

            foreach ($recipients as $userId) {
                try {
                    $service->create([
                        'user_id' => (int) $userId,
                        'type' => 'case_escalated',
                        'title' => "Case #{$row->id} escalated (SLA missed)",
                        'message' => "{$row->case_number} \"{$row->title}\" — priority={$row->priority}, due={$row->sla_due_at}",
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('case_escalated notification failed', [
                        'case_id' => $row->id,
                        'recipient_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('cases:escalate-overdue', ['escalated' => $affected]);
        $this->info("Escalated {$affected} case(s).");

        return self::SUCCESS;
    }
}
