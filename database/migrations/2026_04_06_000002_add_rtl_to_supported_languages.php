<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supported_languages', function (Blueprint $table): void {
            $table->boolean('rtl')->default(false)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('supported_languages', function (Blueprint $table): void {
            $table->dropColumn('rtl');
        });
    }
};
