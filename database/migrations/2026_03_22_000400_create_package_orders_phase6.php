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
        Schema::create('package_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->string('booking_channel', 32)->default('public_b2c');
            $table->string('status', 32)->default('pending_payment');
            $table->string('payment_status', 32)->default('unpaid');
            $table->unsignedSmallInteger('adults_count')->default(1);
            $table->unsignedSmallInteger('children_count')->default(0);
            $table->unsignedSmallInteger('infants_count')->default(0);
            $table->string('currency', 3);
            $table->decimal('base_component_total_snapshot', 12, 2);
            $table->decimal('discount_snapshot', 12, 2)->default(0);
            $table->decimal('markup_snapshot', 12, 2)->default(0);
            $table->decimal('addon_total_snapshot', 12, 2)->default(0);
            $table->decimal('final_total_snapshot', 12, 2);
            $table->string('display_price_mode_snapshot', 32)->default('total');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('package_id');
            $table->index('user_id');
            $table->index('company_id');
        });

        Schema::create('package_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_order_id')->constrained('package_orders')->cascadeOnDelete();
            $table->foreignId('package_component_id')->nullable()->constrained('package_components')->nullOnDelete();
            $table->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $table->string('module_type', 32);
            $table->string('package_role', 32);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->boolean('is_required')->default(true);
            $table->decimal('price_snapshot', 12, 2);
            $table->string('currency_snapshot', 3);
            $table->string('status', 32)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('package_order_id');
            $table->index('offer_id');
        });

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach (['package_orders.view', 'package_orders.manage'] as $name) {
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
        Schema::dropIfExists('package_order_items');
        Schema::dropIfExists('package_orders');

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'package_orders.view',
                'package_orders.manage',
            ])->delete();
        }
    }
};
