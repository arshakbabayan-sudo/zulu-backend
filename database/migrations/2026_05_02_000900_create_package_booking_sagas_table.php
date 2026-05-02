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

        Schema::create('package_booking_sagas', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('failure_reason')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('context')->nullable()
                : $table->json('context')->nullable();

            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('package_id')->references('id')->on('packages')->nullOnDelete();

            $table->unique('order_id'); // one saga per order
            $table->index('status');
            $table->index('package_id');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE package_booking_sagas ADD CONSTRAINT package_booking_sagas_status_check CHECK (status IN ('pending','reserving','reserved','confirming','confirmed','failed','rolling_back','rolled_back','refunded'))");
        }

        Schema::create('saga_component_states', function (Blueprint $table) use ($driver): void {
            $table->bigIncrements('id');
            $table->uuid('saga_id');
            $table->unsignedBigInteger('package_component_id')->nullable();
            $table->uuid('order_item_id')->nullable();
            $table->string('service_type'); // flight | hotel | transfer | car | excursion | visa | insurance
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->unique();
            $table->string('supplier_ref')->nullable();
            $table->text('error_message')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('reservation_payload')->nullable()
                : $table->json('reservation_payload')->nullable();

            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();

            $table->timestamps();

            $table->foreign('saga_id')->references('id')->on('package_booking_sagas')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();

            $table->index('saga_id');
            $table->index('status');
            $table->index(['saga_id', 'status']);
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE saga_component_states ADD CONSTRAINT saga_component_states_status_check CHECK (status IN ('pending','reserving','reserved','confirmed','failed','rolled_back'))");
            DB::statement("ALTER TABLE saga_component_states ADD CONSTRAINT saga_component_states_service_type_check CHECK (service_type IN ('flight','hotel','transfer','car','excursion','visa','insurance'))");
        }

        Schema::create('saga_state_log', function (Blueprint $table) use ($driver): void {
            $table->bigIncrements('id');
            $table->uuid('saga_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('event'); // proposed | reserve_started | component_reserved | component_failed | rollback_started | rolled_back | confirmed | refunded
            $table->unsignedBigInteger('component_state_id')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('details')->nullable()
                : $table->json('details')->nullable();

            $table->timestamp('happened_at');
            $table->timestamps();

            $table->foreign('saga_id')->references('id')->on('package_booking_sagas')->cascadeOnDelete();
            $table->foreign('component_state_id')->references('id')->on('saga_component_states')->nullOnDelete();

            $table->index('saga_id');
            $table->index('happened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_state_log');
        Schema::dropIfExists('saga_component_states');
        Schema::dropIfExists('package_booking_sagas');
    }
};
