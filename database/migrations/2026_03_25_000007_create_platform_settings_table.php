<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string, integer, decimal, boolean, json
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('platform_settings')->insertOrIgnore([
            'key' => 'b2c_markup_percent',
            'value' => '15',
            'type' => 'decimal',
            'description' => 'Default B2C retail markup percentage over B2B price',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
