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
        // Tables whose user-linked rows must survive (financial / audit obligations)
        // but whose PII columns get blanked. Phase 3.6 of remaining-work-2026-05-20
        // roadmap expanded coverage from 3 tables (orders, audit_log_entries,
        // support_tickets) to include passengers, visa_applications,
        // insurance_policies, and payment_methods per GDPR audit High finding.
        $tablesToAnonymise = [
            // table => columns to null
            'orders' => ['contact_email', 'contact_phone'],
            'audit_log_entries' => ['actor_email', 'actor_name'],
            'support_tickets' => ['contact_email', 'contact_phone'],

            // Phase 3.6 additions — PII on retained financial/regulatory rows.
            'passengers' => [
                'first_name', 'last_name',
                'passport_number', 'passport_expiry', 'nationality',
                'date_of_birth', 'gender',
                'email', 'phone',
            ],
            'visa_applications' => [
                'passport_number', 'admin_notes',
            ],
            'insurance_policies' => [
                'insured_name', 'insured_email', 'insured_phone',
                'insured_passport_number', 'insured_date_of_birth',
                'insured_nationality',
            ],
            'payment_methods' => [
                'cardholder_name', 'billing_email', 'billing_phone',
                'billing_address',
            ],
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

        // Phase 3.7 — passport scan / visa documentation files physically removed
        // from disk. These live in storage/app/private/visa-applications/... and
        // are referenced from visa_applications.files (JSON array of paths).
        $this->purgeUserUploadedFiles($user);
    }

    /**
     * Phase 3.7 — physically delete uploaded files owned by the user from
     * the storage disks. The DB row stays (with file paths nulled / anonymised
     * above), but the underlying bytes are removed so a future disk-image
     * leak cannot reveal the user's passport photo.
     */
    private function purgeUserUploadedFiles(User $user): void
    {
        if (! DB::getSchemaBuilder()->hasTable('visa_applications')) {
            return;
        }

        $rows = DB::table('visa_applications')
            ->where('user_id', $user->id)
            ->select(['id', 'files'])
            ->get();

        foreach ($rows as $row) {
            if (empty($row->files)) {
                continue;
            }
            $files = is_string($row->files) ? json_decode($row->files, true) : (array) $row->files;
            if (! is_array($files)) {
                continue;
            }

            foreach ($files as $relativePath) {
                if (! is_string($relativePath) || $relativePath === '') {
                    continue;
                }
                try {
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($relativePath);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete visa file during purge', [
                        'user_id' => $user->id,
                        'path' => $relativePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Null the files array on the row so even the path metadata is gone.
            DB::table('visa_applications')
                ->where('id', $row->id)
                ->update(['files' => null]);
        }
    }
}
