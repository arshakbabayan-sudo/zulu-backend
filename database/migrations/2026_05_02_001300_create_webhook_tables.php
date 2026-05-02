<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('webhook_subscriptions', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('target_url', 500);
            $table->string('secret', 128);

            // Array of event names: order.paid, voucher.issued, contract.signed, etc.
            $driver === 'pgsql'
                ? $table->jsonb('events')
                : $table->json('events');

            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index('company_id');
            $table->index('active');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('event');
            $table->string('idempotency_key', 64)->unique();

            $driver === 'pgsql'
                ? $table->jsonb('payload')
                : $table->json('payload');

            $table->string('status')->default('pending'); // pending | success | failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('first_attempted_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('webhook_subscriptions')->cascadeOnDelete();
            $table->index('subscription_id');
            $table->index('status');
            $table->index('event');
            $table->index('created_at');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending','success','failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
