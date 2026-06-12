<?php

namespace App\Console\Commands;

use App\Models\AdminNotice;
use App\Services\Notifications\AdminBroadcastService;
use Illuminate\Console\Command;

/**
 * notices:send-due — fan out scheduled admin notices whose time has come
 * (Settings → System notifications). Runs every five minutes from the
 * scheduler; paused (is_active=false) notices are skipped and stay queued
 * until re-activated or re-scheduled.
 */
class SendDueAdminNotices extends Command
{
    protected $signature = 'notices:send-due';

    protected $description = 'Send scheduled admin notices whose scheduled_for has passed';

    public function handle(AdminBroadcastService $broadcast): int
    {
        $due = AdminNotice::query()
            ->whereNull('sent_at')
            ->where('is_active', true)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->get();

        $sent = 0;
        foreach ($due as $notice) {
            $userIds = $broadcast->resolveRecipients($notice->audience, $notice->company_id);
            // An empty audience still marks the notice sent (count 0) — a
            // retry every five minutes would never produce recipients for the
            // same selector and would loop forever.
            if ($userIds !== []) {
                $broadcast->dispatch(
                    $userIds,
                    $broadcast->normaliseChannels($notice->channels),
                    $notice->title,
                    $notice->message,
                    $notice->priority ?? 'normal',
                );
            }
            $notice->forceFill(['sent_at' => now(), 'sent_count' => count($userIds)])->save();
            $sent++;
        }

        $this->info("Sent {$sent} due notice(s).");

        return self::SUCCESS;
    }
}
