<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['mirror_order_id']);
            $table->dropColumn('mirror_order_id');
        });

        Schema::table('package_orders', function (Blueprint $table): void {
            $table->dropIndex(['mirror_order_id']);
            $table->dropColumn('mirror_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->uuid('mirror_order_id')->nullable()->index()->after('currency');
        });

        Schema::table('package_orders', function (Blueprint $table): void {
            $table->uuid('mirror_order_id')->nullable()->index()->after('currency');
        });
    }
};
