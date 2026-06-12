<?php

namespace App\Services\Notifications;

use App\Jobs\SendBulkNotificationJob;
use App\Models\User;
use App\Models\UserCompany;

/**
 * Shared audience resolution + fan-out for admin broadcasts. Used by BOTH the
 * /notifications/bulk-send endpoint and the Settings → System notifications
 * notices (immediate send + the notices:send-due scheduler), so the recipient
 * semantics can never drift between the two entry points.
 */
class AdminBroadcastService
{
    public const CHANNELS = ['in_app', 'email', 'sms', 'push'];

    /**
     * Resolve a recipient selector to user ids.
     * Targets: everyone | all_b2c | all_staff | by_company | specific_users.
     *
     * @param  list<int>|null  $userIds
     * @return list<int>
     */
    public function resolveRecipients(string $target, ?int $companyId = null, ?array $userIds = null): array
    {
        if ($target === 'specific_users') {
            return array_values(array_unique(array_map('intval', $userIds ?? [])));
        }
        if ($target === 'by_company') {
            if ($companyId === null || $companyId <= 0) {
                return [];
            }

            return UserCompany::query()
                ->where('company_id', $companyId)
                ->pluck('user_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        }
        if ($target === 'everyone') {
            return User::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        if ($target === 'all_b2c') {
            return User::query()->whereDoesntHave('memberships')->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        if ($target === 'all_staff') {
            return User::query()->whereHas('memberships')->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return [];
    }

    /**
     * Normalise the channels selector — empty collapses to ['in_app'] (the
     * pre-channels API contract).
     *
     * @param  mixed  $input
     * @return list<string>
     */
    public function normaliseChannels($input): array
    {
        if (! is_array($input) || $input === []) {
            return ['in_app'];
        }
        $picked = array_values(array_unique(array_filter(
            $input,
            static fn ($c) => in_array($c, self::CHANNELS, true),
        )));

        return $picked === [] ? ['in_app'] : $picked;
    }

    /**
     * Queue the fan-out job.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $channels
     */
    public function dispatch(array $userIds, array $channels, string $title, string $message, string $priority = 'normal'): void
    {
        SendBulkNotificationJob::dispatch($userIds, $channels, $title, $message, $priority);
    }
}
