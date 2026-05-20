<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.3 (GDPR High) — newsletter double opt-in.
 *
 * Adds `confirmed_at` (timestamp) + `confirmation_token` (varchar) to
 * `newsletter_subscriptions`. Until the user clicks the confirmation
 * link in their email, the subscription is pending and NO newsletters
 * are dispatched.
 *
 * Existing rows are grandfathered with NULL confirmed_at — operators can
 * choose to re-confirm them via a separate cleanup migration if desired,
 * but for compatibility we treat NULL confirmed_at as "legacy implicitly
 * confirmed".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('newsletter_subscriptions')) {
            return;
        }

        Schema::table('newsletter_subscriptions', function (Blueprint $t): void {
            if (! Schema::hasColumn('newsletter_subscriptions', 'confirmed_at')) {
                $t->timestamp('confirmed_at')->nullable()->after('subscribed_at');
            }
            if (! Schema::hasColumn('newsletter_subscriptions', 'confirmation_token')) {
                $t->string('confirmation_token', 64)->nullable()->after('confirmed_at');
                $t->index('confirmation_token', 'newsletter_subs_confirmation_token_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('newsletter_subscriptions')) {
            return;
        }

        Schema::table('newsletter_subscriptions', function (Blueprint $t): void {
            if (Schema::hasColumn('newsletter_subscriptions', 'confirmation_token')) {
                $t->dropIndex('newsletter_subs_confirmation_token_idx');
                $t->dropColumn('confirmation_token');
            }
            if (Schema::hasColumn('newsletter_subscriptions', 'confirmed_at')) {
                $t->dropColumn('confirmed_at');
            }
        });
    }
};
