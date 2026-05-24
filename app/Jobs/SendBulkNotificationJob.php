<?php

namespace App\Jobs;

use App\Mail\AdminBulkNotificationMail;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\PushProvider;
use App\Services\Notifications\SmsProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fans out a single admin-broadcast across the channels the operator
 * picked on the bulk-notifications page (in_app | email | sms | push).
 *
 * Per channel, per recipient:
 *  - in_app → create a Notification row tagged with delivered_channels
 *  - email  → queue an AdminBulkNotificationMail
 *  - sms    → call SmsProvider::send (NoopSmsProvider by default)
 *  - push   → call PushProvider::sendToUser (NoopPushProvider by default)
 *
 * One job per (channel, batch-of-recipients) keeps each unit small and
 * lets Horizon/queue retry just the failed slice. The controller fires
 * one job for the whole recipient list and lets the job loop internally;
 * shard further when recipient lists balloon past ~5k.
 */
class SendBulkNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $channels
     */
    public function __construct(
        public array $userIds,
        public array $channels,
        public string $title,
        public string $message,
        public string $priority = 'normal',
    ) {}

    public function handle(SmsProvider $sms, PushProvider $push): void
    {
        $channels = array_values(array_unique(array_filter(
            $this->channels,
            static fn ($c) => in_array($c, ['in_app', 'email', 'sms', 'push'], true),
        )));

        if ($channels === [] || $this->userIds === []) {
            return;
        }

        $now = now();

        // We hydrate users in chunks so we can read email + phone for the
        // email/sms paths without N+1 calls. Push needs only the user id.
        User::query()
            ->whereIn('id', $this->userIds)
            ->select(['id', 'email', 'phone'])
            ->chunkById(500, function ($chunk) use ($channels, $now, $sms, $push): void {
                foreach ($chunk as $user) {
                    $delivered = [];

                    if (in_array('in_app', $channels, true)) {
                        Notification::query()->create([
                            'user_id' => (int) $user->id,
                            'type' => 'admin_broadcast',
                            'title' => $this->title,
                            'message' => $this->message,
                            'status' => 'unread',
                            'event_type' => 'admin_broadcast',
                            'priority' => $this->priority,
                            'delivered_channels' => $this->resolveDeliveredChannels($channels, $user),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $delivered[] = 'in_app';
                    }

                    if (in_array('email', $channels, true) && is_string($user->email) && $user->email !== '') {
                        try {
                            Mail::to($user->email)->queue(new AdminBulkNotificationMail(
                                subjectText: $this->title,
                                bodyText: $this->message,
                                priority: $this->priority,
                            ));
                            $delivered[] = 'email';
                        } catch (\Throwable $e) {
                            Log::warning('Bulk notification email queue failed', [
                                'user_id' => $user->id,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    if (in_array('sms', $channels, true) && is_string($user->phone) && $user->phone !== '') {
                        try {
                            if ($sms->send((string) $user->phone, $this->renderSmsBody())) {
                                $delivered[] = 'sms';
                            }
                        } catch (\Throwable $e) {
                            Log::warning('Bulk notification SMS failed', [
                                'user_id' => $user->id,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    if (in_array('push', $channels, true)) {
                        try {
                            if ($push->sendToUser((int) $user->id, $this->title, $this->message)) {
                                $delivered[] = 'push';
                            }
                        } catch (\Throwable $e) {
                            Log::warning('Bulk notification push failed', [
                                'user_id' => $user->id,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    Log::info('admin_broadcast.delivered', [
                        'user_id' => $user->id,
                        'channels' => $delivered,
                        'title' => $this->title,
                    ]);
                }
            });
    }

    /**
     * Compute the per-user delivered_channels list to store on the
     * in_app Notification row. We can't await SMS/push results here
     * (they happen after the row is created), so we record the channels
     * the operator *requested* and the user is *reachable on* — channels
     * actually-delivered are reflected in the Log lines.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function resolveDeliveredChannels(array $channels, User $user): array
    {
        $resolved = [];
        foreach ($channels as $channel) {
            if ($channel === 'in_app') {
                $resolved[] = 'in_app';

                continue;
            }
            if ($channel === 'email' && is_string($user->email) && $user->email !== '') {
                $resolved[] = 'email';

                continue;
            }
            if ($channel === 'sms' && is_string($user->phone) && $user->phone !== '') {
                $resolved[] = 'sms';

                continue;
            }
            if ($channel === 'push') {
                // Push reachability depends on device tokens; we can't
                // know synchronously, so we still tag the row.
                $resolved[] = 'push';
            }
        }

        return $resolved;
    }

    private function renderSmsBody(): string
    {
        // SMS gateways charge per 160-char segment; keep the body tight.
        $combined = trim($this->title.': '.$this->message);
        if (mb_strlen($combined) <= 320) {
            return $combined;
        }

        return mb_substr($combined, 0, 317).'...';
    }
}
