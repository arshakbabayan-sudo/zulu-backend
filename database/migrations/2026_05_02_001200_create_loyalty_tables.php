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

        Schema::create('loyalty_accounts', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->bigInteger('points_balance')->default(0);
            $table->bigInteger('lifetime_points')->default(0);
            $table->string('tier')->default('bronze'); // bronze | silver | gold | platinum
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('tier');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE loyalty_accounts ADD CONSTRAINT loyalty_accounts_tier_check CHECK (tier IN ('bronze','silver','gold','platinum'))");
        }

        Schema::create('loyalty_transactions', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('type'); // earn | redeem | expire | adjust
            $table->bigInteger('points'); // positive for earn/adjust+, negative for redeem/expire/adjust-
            $table->string('source_type')->nullable(); // order, review, referral, manual
            $table->string('source_id')->nullable();
            $table->string('reason')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('metadata')->nullable()
                : $table->json('metadata')->nullable();

            $table->timestamp('happened_at');
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('loyalty_accounts')->cascadeOnDelete();
            $table->index('account_id');
            $table->index('type');
            $table->index('happened_at');
            $table->index(['source_type', 'source_id']);
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE loyalty_transactions ADD CONSTRAINT loyalty_transactions_type_check CHECK (type IN ('earn','redeem','expire','adjust'))");
        }

        Schema::create('loyalty_redemptions', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->uuid('order_id')->nullable();
            $table->bigInteger('points_redeemed');
            $table->decimal('discount_amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status')->default('applied'); // applied | reversed
            $table->timestamp('redeemed_at');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('loyalty_accounts')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->index('account_id');
            $table->index('order_id');
            $table->index('status');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE loyalty_redemptions ADD CONSTRAINT loyalty_redemptions_status_check CHECK (status IN ('applied','reversed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
    }
};
