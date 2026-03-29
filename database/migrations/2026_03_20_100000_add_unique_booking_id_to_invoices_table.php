<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One invoice per booking — prevents duplicate marketplace checkout / concurrent inserts.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['booking_id']);
        });
    }
};
