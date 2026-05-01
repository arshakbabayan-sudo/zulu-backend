<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS invoices_booking_id_unique');
            DB::statement('DROP INDEX IF EXISTS supplier_entitlements_booking_id_index');
            DB::statement('DROP INDEX IF EXISTS supplier_entitlements_booking_item_id_index');
            DB::statement('DROP INDEX IF EXISTS supplier_entitlements_package_order_id_index');
            DB::statement('DROP INDEX IF EXISTS supplier_entitlements_package_order_item_id_index');

            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
                $table->dropForeign(['package_order_id']);
            });

            Schema::table('reviews', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
                $table->dropForeign(['package_order_id']);
            });

            Schema::table('supplier_entitlements', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
                $table->dropForeign(['booking_item_id']);
                $table->dropForeign(['package_order_id']);
                $table->dropForeign(['package_order_item_id']);
            });

            Schema::table('service_holds', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
            });

            Schema::table('bonuses', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
            });
        } else {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign('invoices_booking_id_foreign');
                $table->dropForeign('invoices_package_order_id_foreign');
            });

            Schema::table('reviews', function (Blueprint $table): void {
                $table->dropForeign('reviews_booking_id_foreign');
                $table->dropForeign('reviews_package_order_id_foreign');
            });

            Schema::table('supplier_entitlements', function (Blueprint $table): void {
                $table->dropForeign('supplier_entitlements_booking_id_foreign');
                $table->dropForeign('supplier_entitlements_booking_item_id_foreign');
                $table->dropForeign('supplier_entitlements_package_order_id_foreign');
                $table->dropForeign('supplier_entitlements_package_order_item_id_foreign');
            });

            Schema::table('service_holds', function (Blueprint $table): void {
                $table->dropForeign('service_holds_booking_id_foreign');
            });

            Schema::table('bonuses', function (Blueprint $table): void {
                $table->dropForeign('bonuses_booking_id_foreign');
            });
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['booking_id', 'package_order_id']);
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn(['booking_id', 'package_order_id']);
        });

        Schema::table('supplier_entitlements', function (Blueprint $table): void {
            $table->dropColumn(['booking_id', 'booking_item_id', 'package_order_id', 'package_order_item_id']);
        });

        Schema::table('service_holds', function (Blueprint $table): void {
            $table->dropColumn('booking_id');
        });

        Schema::table('bonuses', function (Blueprint $table): void {
            $table->dropColumn('booking_id');
        });

        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('package_order_items');
        Schema::dropIfExists('package_orders');
    }

    public function down(): void
    {
        throw new RuntimeException('Sprint 1 Step 8b is one-way — restore from backup if needed.');
    }
};
