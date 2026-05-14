<?php

namespace App\Services\UserAccount;

use App\Mail\AccountDeletionCompleted;
use App\Mail\AccountDeletionConfirmation;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountDeletionService
{
    /**
     * Step 1 — user posts DELETE /api/account. We don't actually disable the
     * user yet; we just record the intent and email them a one-time link.
     */
    public function requestDeletion(User $user, ?string $reason, ?string $ip, ?string $userAgent): AccountDeletionRequest
    {
        // Reuse any in-flight pending row instead of creating duplicates.
        $existing = AccountDeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', AccountDeletionRequest::STATUS_PENDING_CONFIRMATION)
            ->first();

        $token = Str::random(64);

        if ($existing) {
            $existing->update([
                'confirmation_token' => $token,
                'confirmation_sent_at' => now(),
                'reason' => $reason,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
            $request = $existing->fresh();
        } else {
            $request = AccountDeletionRequest::query()->create([
                'user_id' => $user->id,
                'status' => AccountDeletionRequest::STATUS_PENDING_CONFIRMATION,
                'confirmation_token' => $token,
                'confirmation_sent_at' => now(),
                'reason' => $reason,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
        }

        try {
            Mail::to($user->email)->queue(new AccountDeletionConfirmation($user, $token));
        } catch (\Throwable $e) {
            Log::warning('Account deletion confirmation email failed to queue', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $request;
    }

    /**
     * Step 2 — user clicks the email link. We flip status to
     * pending_deletion so the rest of the app can show a "scheduled for
     * deletion" banner, but we do NOT soft-delete yet — the user still
     * needs to be able to log in to self-serve a cancel within the
     * 30-day window. Active Sanctum tokens are revoked so old sessions
     * are forced to re-auth and pick up the new status.
     */
    public function confirmDeletion(string $token): ?AccountDeletionRequest
    {
        $request = AccountDeletionRequest::query()
            ->where('confirmation_token', $token)
            ->where('status', AccountDeletionRequest::STATUS_PENDING_CONFIRMATION)
            ->first();

        if (! $request) {
            return null;
        }

        return DB::transaction(function () use ($request) {
            $scheduledFor = now()->addDays(AccountDeletionRequest::GRACE_DAYS);

            $request->update([
                'status' => AccountDeletionRequest::STATUS_SCHEDULED,
                'confirmation_token' => null,
                'confirmed_at' => now(),
                'scheduled_for' => $scheduledFor,
            ]);

            $user = User::query()->find($request->user_id);
            if ($user) {
                $user->status = User::STATUS_PENDING_DELETION;
                $user->save();
                if (method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }
                // Note: no $user->delete() here — soft-delete happens during
                // purgeExpired() once the 30-day window elapses.
            }

            return $request->fresh();
        });
    }

    /**
     * Step 3 — user changes their mind within the 30-day window.
     * Flips status back to active and marks the request cancelled.
     */
    public function cancelDeletion(int $userId): ?AccountDeletionRequest
    {
        $request = AccountDeletionRequest::query()
            ->where('user_id', $userId)
            ->where('status', AccountDeletionRequest::STATUS_SCHEDULED)
            ->latest('id')
            ->first();

        if (! $request) {
            return null;
        }

        return DB::transaction(function () use ($request) {
            $request->update([
                'status' => AccountDeletionRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $user = User::withTrashed()->find($request->user_id);
            if ($user) {
                if ($user->trashed()) {
                    $user->restore();
                }
                $user->status = User::STATUS_ACTIVE;
                $user->save();
            }

            return $request->fresh();
        });
    }

    /**
     * Step 4 — scheduler picks this up. Force-deletes the user row and
     * anonymises records we can't legally drop (booking history, audit log).
     */
    public function purgeExpired(): int
    {
        $due = AccountDeletionRequest::query()
            ->where('status', AccountDeletionRequest::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now())
            ->get();

        $count = 0;
        foreach ($due as $request) {
            try {
                DB::transaction(function () use ($request) {
                    $user = User::withTrashed()->find($request->user_id);
                    if ($user) {
                        try {
                            Mail::to($user->email)->queue(new AccountDeletionCompleted($user->email, $user->name ?? ''));
                        } catch (\Throwable $e) {
                            // mail failure shouldn't block purge
                        }

                        $this->anonymiseRetainedRows($user);
                        $user->forceDelete();
                    }

                    $request->update([
                        'status' => AccountDeletionRequest::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
                });
                $count++;
            } catch (\Throwable $e) {
                Log::error('Account purge failed', [
                    'request_id' => $request->id,
                    'user_id' => $request->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Rows we cannot drop entirely for legal / financial reasons get
     * their personal identifiers blanked so the foreign user_id can stay
     * (anonymised booking history etc.).
     */
    private function anonymiseRetainedRows(User $user): void
    {
        $tablesToAnonymise = [
            // table => columns to null + email-style override
            'orders' => ['contact_email', 'contact_phone'],
            'audit_log_entries' => ['actor_email', 'actor_name'],
            'support_tickets' => ['contact_email', 'contact_phone'],
        ];

        foreach ($tablesToAnonymise as $table => $columns) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $updates = [];
            foreach ($columns as $col) {
                if (DB::getSchemaBuilder()->hasColumn($table, $col)) {
                    $updates[$col] = null;
                }
            }
            if (! empty($updates)) {
                DB::table($table)->where('user_id', $user->id)->update($updates);
            }
        }
    }
}
