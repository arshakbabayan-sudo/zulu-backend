<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_entitlements')) {
            return;
        }

        if (Schema::hasColumn('supplier_entitlements', 'booking_id')) {
            return;
        }

        Schema::table('supplier_entitlements', function (Blueprint $table): void {
            $table->dropForeign(['package_order_id']);
            $table->dropForeign(['package_order_item_id']);
        });

        Schema::table('supplier_entitlements', function (Blueprint $table): void {
            $table->unsignedBigInteger('package_order_id')->nullable()->change();
            $table->unsignedBigInteger('package_order_item_id')->nullable()->change();
        });

        Schema::table('supplier_entitlements', function (Blueprint $table): void {
            $table->foreign('package_order_id')
                ->references('id')
                ->on('package_orders')
                ->cascadeOnDelete();
            $table->foreign('package_order_item_id')
                ->references('id')
                ->on('package_order_items')
                ->cascadeOnDelete();
        });

        Schema::table('supplier_entitlements', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete()->after('package_order_item_id');
            $table->foreignId('booking_item_id')->nullable()->constrained('booking_items')->nullOnDelete()->after('booking_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('supplier_entitlements')) {
            return;
        }

        Schema::table('supplier_entitlements', function (Blueprint $table): void {
            if (Schema::hasColumn('supplier_entitlements', 'booking_item_id')) {
                $table->dropForeign(['booking_item_id']);
                $table->dropColumn('booking_item_id');
            }
            if (Schema::hasColumn('supplier_entitlements', 'booking_id')) {
                $table->dropForeign(['booking_id']);
                $table->dropColumn('booking_id');
            }
        });
    }
};
