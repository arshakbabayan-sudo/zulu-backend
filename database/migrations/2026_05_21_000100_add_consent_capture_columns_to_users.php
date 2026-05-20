<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.2 (GDPR Critical) — capture consent at registration.
 *
 * Article 7 (Conditions for Consent) + Article 13 (Information to be Provided)
 * require recording the moment, IP, and policy version the user consented to.
 * Pre-deploy state: users table had no consent fields — registration accepted
 * email/password with no record of agreement to Terms or Privacy Policy.
 *
 * Adds three nullable columns to `users`:
 *  - terms_accepted_at  (timestamp) — when the user clicked "I agree"
 *  - consent_ip          (varchar 45)  — IPv4/IPv6 address at consent time
 *  - consent_version     (varchar 32)  — policy version string (e.g. "v1-2026-05")
 *
 * Existing users (pre-migration) have NULL consent — they are grandfathered
 * (registered before this requirement existed). New registrations going
 * forward will be hard-required to fill these.
 *
 * docs/audits/gdpr-compliance-2026-05-20.md Critical #2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $t): void {
            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $t->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
            }
            if (! Schema::hasColumn('users', 'consent_ip')) {
                $t->string('consent_ip', 45)->nullable()->after('terms_accepted_at');
            }
            if (! Schema::hasColumn('users', 'consent_version')) {
                $t->string('consent_version', 32)->nullable()->after('consent_ip');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $t): void {
            foreach (['terms_accepted_at', 'consent_ip', 'consent_version'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
