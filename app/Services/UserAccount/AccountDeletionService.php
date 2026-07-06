<?php

namespace App\Services\UserAccount;

use App\Mail\AccountDeletionCompleted;
use App\Mail\AccountDeletionConfirmation;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
     * Admin-initiated anonymisation (Phase 7.1 "Ջնջել" button).
     * Default platform-admin action — preserves financial/contractual history
     * but blanks PII so the row identity becomes "Անանուն օգտատեր #{id}".
     * Soft-deletes the user so they vanish from active admin lists but the
     * row is still recoverable via withTrashed() if anonymisation was a mistake
     * (data itself is not recoverable, only the marker).
     */
    public function adminAnonymize(User $user, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($user) {
            $user->name = 'Անանուն օգտատեր #'.$user->id;
            $user->email = 'anon-'.$user->id.'@deleted.zulu.am';
            $user->phone = null;
            $user->avatar = null;
            $user->birth_date = null;
            $user->nationality = null;
            $user->google_id = null;
            $user->facebook_id = null;
            $user->oauth_provider = null;
            $user->password = bcrypt(Str::random(40));
            $user->status = User::STATUS_PENDING_DELETION;
            $user->save();

            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            $this->anonymiseRetainedRows($user);

            // Soft-delete so the user disappears from active listings.
            $user->delete();
        });

        app(AuditService::class)->log([
            'category' => 'user_admin_action',
            'subject_type' => 'User',
            'subject_id' => (string) $user->id,
            'action' => 'admin_anonymize',
            'actor' => $actor,
            'context' => ['reason' => $reason],
        ]);
    }

    /**
     * Admin-initiated hard delete (Phase 7.1 "Ամբողջությամբ ջնջել" button).
     * Super-admin only — physically removes the user row. Retained-table PII
     * is still anonymised first so booking/audit history loses identifying
     * data even though the rows themselves remain (financial/legal record).
     */
    public function adminHardDelete(User $user, User $actor, string $reason): void
    {
        $userId = (int) $user->id;
        $userEmail = (string) $user->email;
        $userName = (string) ($user->name ?? '');

        DB::transaction(function () use ($user) {
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
            $this->anonymiseRetainedRows($user);
            $user->forceDelete();
        });

        app(AuditService::class)->log([
            'category' => 'user_admin_action',
            'subject_type' => 'User',
            'subject_id' => (string) $userId,
            'action' => 'admin_hard_delete',
            'actor' => $actor,
            'context' => [
                'reason' => $reason,
                'deleted_email' => $userEmail,
                'deleted_name' => $userName,
            ],
        ]);
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
            // NOTE (2026-07-06): 'passengers' is deliberately NOT in this map.
            // The passengers table has NO user_id column (it links to users via
            // booking_passengers.passenger_id → bookings.user_id), so keeping it
            // here made this loop run `UPDATE passengers ... WHERE user_id = ?`
            // → Postgres 42703 → 500 on EVERY admin anonymize/hard-delete/purge.
            // Passengers are handled by anonymisePassengerRows() below through
            // the real relation chain.
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
            // Defensive guard (2026-07-06): this generic loop is keyed on a
            // user_id column. A table without one must be skipped, never
            // updated — otherwise the UPDATE throws (Postgres 42703) and the
            // surrounding transaction rolls back the whole deletion.
            if (! DB::getSchemaBuilder()->hasColumn($table, 'user_id')) {
                Log::warning('anonymiseRetainedRows: table has no user_id column, skipped', [
                    'table' => $table,
                    'user_id' => $user->id,
                ]);

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

        // Passengers link to the user indirectly (no user_id column) — handled
        // through the booking relation chain.
        $this->anonymisePassengerRows($user);

        // Phase 3.7 — passport scan / visa documentation files physically removed
        // from disk. These live in storage/app/private/visa-applications/... and
        // are referenced from visa_applications.files (JSON array of paths).
        $this->purgeUserUploadedFiles($user);
    }

    /**
     * Passengers carry heavy PII but have NO user_id column — they attach to a
     * user through booking_passengers.passenger_id → booking_passengers.booking_id
     * → bookings.user_id (migrations 2026_03_25_000004 / _000005). Anonymise
     * every passenger that appears on one of the user's bookings.
     *
     * Column handling mirrors the schema's nullability:
     *  - first_name / last_name are NOT NULL → placeholder 'Anonymized'
     *  - all other PII columns are nullable → NULL
     *  - passenger_type (adult/child/infant) is kept — non-identifying, and
     *    pricing/reporting history depends on it.
     *
     * Pure query-builder (whereIn + join subquery) so it runs identically on
     * Postgres (prod) and SQLite (CI tests).
     */
    private function anonymisePassengerRows(User $user): void
    {
        $schema = DB::getSchemaBuilder();

        if ($schema->hasTable('passengers')
            && $schema->hasTable('booking_passengers')
            && $schema->hasTable('bookings')) {
            DB::table('passengers')
                ->whereIn('id', function ($query) use ($user): void {
                    $query->select('booking_passengers.passenger_id')
                        ->from('booking_passengers')
                        ->join('bookings', 'bookings.id', '=', 'booking_passengers.booking_id')
                        ->where('bookings.user_id', $user->id);
                })
                ->update([
                    'first_name' => 'Anonymized',
                    'last_name' => 'Anonymized',
                    'passport_number' => null,
                    'passport_expiry' => null,
                    'nationality' => null,
                    'date_of_birth' => null,
                    'gender' => null,
                    'email' => null,
                    'phone' => null,
                ]);
        }

        // order_items.passenger_data (migration 2026_04_26_000300) holds a JSON
        // copy of the passenger manifest for the user's orders — blank it too so
        // no PII survives in the snapshot column.
        if ($schema->hasTable('order_items')
            && $schema->hasTable('orders')
            && $schema->hasColumn('order_items', 'passenger_data')) {
            DB::table('order_items')
                ->whereIn('order_id', function ($query) use ($user): void {
                    $query->select('id')->from('orders')->where('user_id', $user->id);
                })
                ->update(['passenger_data' => null]);
        }
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
                    Storage::disk('local')->delete($relativePath);
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
