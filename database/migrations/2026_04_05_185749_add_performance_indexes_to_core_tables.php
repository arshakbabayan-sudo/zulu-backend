<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->index('company_id', 'offers_company_id_idx');
            $table->index('status', 'offers_status_idx');
            $table->index('type', 'offers_type_idx');
            $table->index(['company_id', 'status', 'type'], 'offers_company_status_type_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index('company_id', 'bookings_company_id_idx');
            $table->index(['company_id', 'status'], 'bookings_company_status_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'notifications_user_status_idx');
            $table->index(['user_id', 'created_at'], 'notifications_user_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex('offers_company_id_idx');
            $table->dropIndex('offers_status_idx');
            $table->dropIndex('offers_type_idx');
            $table->dropIndex('offers_company_status_type_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_company_id_idx');
            $table->dropIndex('bookings_company_status_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_status_idx');
            $table->dropIndex('notifications_user_created_idx');
        });
    }
};
