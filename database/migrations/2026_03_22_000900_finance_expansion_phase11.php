<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('settlements')) {
            Schema::create('settlements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->string('currency', 3);
                $table->decimal('total_gross_amount', 12, 2)->default(0);
                $table->decimal('total_commission_amount', 12, 2)->default(0);
                $table->decimal('total_net_amount', 12, 2)->default(0);
                $table->unsignedInteger('entitlements_count')->default(0);
                $table->string('status', 32)->default('pending');
                $table->string('period_label', 128)->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('company_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('supplier_entitlements')) {
            Schema::create('supplier_entitlements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('package_order_id')->constrained('package_orders')->cascadeOnDelete();
                $table->foreignId('package_order_item_id')->constrained('package_order_items')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->string('service_type', 64);
                $table->decimal('gross_amount', 12, 2);
                $table->decimal('commission_amount', 12, 2)->default(0);
                $table->decimal('net_amount', 12, 2);
                $table->string('currency', 3);
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('settlement_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('package_order_id');
                $table->index('company_id');
                $table->index('status');
            });

            Schema::table('supplier_entitlements', function (Blueprint $table): void {
                $table->foreign('settlement_id')
                    ->references('id')
                    ->on('settlements')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach ([
                'finance.entitlements.view',
                'finance.entitlements.manage',
                'finance.settlements.view',
                'finance.settlements.manage',
                'commissions.create',
                'commissions.update',
            ] as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'finance.entitlements.view',
                'finance.entitlements.manage',
                'finance.settlements.view',
                'finance.settlements.manage',
                'commissions.create',
                'commissions.update',
            ])->delete();
        }

        if (Schema::hasTable('supplier_entitlements')) {
            Schema::table('supplier_entitlements', function (Blueprint $table): void {
                $table->dropForeign(['settlement_id']);
            });
            Schema::dropIfExists('supplier_entitlements');
        }

        Schema::dropIfExists('settlements');
    }
};
