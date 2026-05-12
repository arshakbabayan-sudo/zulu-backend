<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED_SOURCES = ['bottom_form', 'middle_form', 'footer', 'other'];

    public function up(): void
    {
        if (Schema::hasTable('newsletter_subscriptions')) {
            return;
        }

        Schema::create('newsletter_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191);
            $table->string('lang', 5)->default('en');
            $table->string('source', 32)->default('bottom_form');
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('subscribed_at')->useCurrent();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['email', 'lang'], 'newsletter_subs_email_lang_unique');
            $table->index(['source', 'subscribed_at'], 'newsletter_subs_source_date_idx');
            $table->index('unsubscribed_at', 'newsletter_subs_unsubscribed_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $allowed = "'".implode("','", self::ALLOWED_SOURCES)."'";
            DB::statement(
                'ALTER TABLE newsletter_subscriptions DROP CONSTRAINT IF EXISTS newsletter_subs_source_check'
            );
            DB::statement(
                'ALTER TABLE newsletter_subscriptions ADD CONSTRAINT newsletter_subs_source_check '
                ."CHECK (source IN ({$allowed}))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE newsletter_subscriptions DROP CONSTRAINT IF EXISTS newsletter_subs_source_check');
        }

        Schema::dropIfExists('newsletter_subscriptions');
    }
};
