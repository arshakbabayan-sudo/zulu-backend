<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a JSON column to record which channels a notification was
     * delivered through (e.g., ["in_app","email"] for a bulk broadcast).
     * Backfilled to ["in_app"] for legacy rows.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'delivered_channels')) {
                $table->json('delivered_channels')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (Schema::hasColumn('notifications', 'delivered_channels')) {
                $table->dropColumn('delivered_channels');
            }
        });
    }
};
