<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'check_in')) {
                $table->date('check_in')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'check_out')) {
                $table->date('check_out')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'nights')) {
                $table->integer('nights')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'room_nights')) {
                $table->integer('room_nights')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'avg_daily_rate')) {
                $table->decimal('avg_daily_rate', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'hotel_name')) {
                $table->string('hotel_name')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'hotel_line')) {
                $table->string('hotel_line')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'room_type')) {
                $table->string('room_type')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'rate_name')) {
                $table->string('rate_name')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'hotel_order_id')) {
                $table->string('hotel_order_id')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'guest_names')) {
                $table->text('guest_names')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'adults_count')) {
                $table->integer('adults_count')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'children_count')) {
                $table->integer('children_count')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'meal_plan')) {
                $table->string('meal_plan')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'supplier_id')) {
                $table->string('supplier_id')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'order_source')) {
                $table->string('order_source')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'promo_code')) {
                $table->string('promo_code')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'promo_code')) {
                $table->dropColumn('promo_code');
            }
            if (Schema::hasColumn('invoices', 'order_source')) {
                $table->dropColumn('order_source');
            }
            if (Schema::hasColumn('invoices', 'supplier_id')) {
                $table->dropColumn('supplier_id');
            }
            if (Schema::hasColumn('invoices', 'meal_plan')) {
                $table->dropColumn('meal_plan');
            }
            if (Schema::hasColumn('invoices', 'children_count')) {
                $table->dropColumn('children_count');
            }
            if (Schema::hasColumn('invoices', 'adults_count')) {
                $table->dropColumn('adults_count');
            }
            if (Schema::hasColumn('invoices', 'guest_names')) {
                $table->dropColumn('guest_names');
            }
            if (Schema::hasColumn('invoices', 'hotel_order_id')) {
                $table->dropColumn('hotel_order_id');
            }
            if (Schema::hasColumn('invoices', 'rate_name')) {
                $table->dropColumn('rate_name');
            }
            if (Schema::hasColumn('invoices', 'room_type')) {
                $table->dropColumn('room_type');
            }
            if (Schema::hasColumn('invoices', 'hotel_line')) {
                $table->dropColumn('hotel_line');
            }
            if (Schema::hasColumn('invoices', 'hotel_name')) {
                $table->dropColumn('hotel_name');
            }
            if (Schema::hasColumn('invoices', 'avg_daily_rate')) {
                $table->dropColumn('avg_daily_rate');
            }
            if (Schema::hasColumn('invoices', 'room_nights')) {
                $table->dropColumn('room_nights');
            }
            if (Schema::hasColumn('invoices', 'nights')) {
                $table->dropColumn('nights');
            }
            if (Schema::hasColumn('invoices', 'check_out')) {
                $table->dropColumn('check_out');
            }
            if (Schema::hasColumn('invoices', 'check_in')) {
                $table->dropColumn('check_in');
            }
        });
    }
};
