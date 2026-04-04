<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $table): void {
            $table->json('selected_client_ids')->nullable()->after('client_targeting');
        });
    }

    public function down(): void
    {
        Schema::table('service_connections', function (Blueprint $table): void {
            $table->dropColumn('selected_client_ids');
        });
    }
};
