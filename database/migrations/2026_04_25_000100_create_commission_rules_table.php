<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_rules')) {
            $driver = DB::connection()->getDriverName();

            Schema::create('commission_rules', function (Blueprint $table) use ($driver): void {
                $table->uuid('id')->primary();
                $table->enum('type', ['percentage', 'fixed', 'hybrid']);
                $table->enum('level', ['global', 'category', 'seller', 'partner_agreement']);
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->string('service_type')->nullable();
                $table->decimal('percentage_value', 8, 4)->nullable();
                $table->decimal('fixed_value', 15, 4)->nullable();
                $table->char('fixed_currency', 3)->nullable();
                $driver === 'pgsql'
                    ? $table->jsonb('hybrid_config')->nullable()
                    : $table->json('hybrid_config')->nullable();
                $driver === 'pgsql'
                    ? $table->jsonb('tiered_config')->nullable()
                    : $table->json('tiered_config')->nullable();
                $table->enum('direction', ['zulu_from_seller', 'seller_from_reseller'])->default('zulu_from_seller');
                $table->integer('priority')->default(0);
                $table->timestamp('effective_from');
                $table->timestamp('effective_to')->nullable();
                $table->enum('status', ['active', 'inactive', 'scheduled'])->default('active');
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['level', 'active', 'effective_from', 'effective_to'], 'commission_rules_resolver_scan_idx');
                $table->index('service_type', 'commission_rules_service_type_idx');
                $table->index(['scope_id', 'level'], 'commission_rules_scope_level_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
