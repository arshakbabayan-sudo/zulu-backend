<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $table): void {
            $table->json('city_rules')->nullable()->after('selected_client_ids');
            $table->json('status_history')->nullable()->after('city_rules');
        });
    }

    public function down(): void
    {
        Schema::table('service_connections', function (Blueprint $table): void {
            $table->dropColumn(['city_rules', 'status_history']);
        });
    }
};
