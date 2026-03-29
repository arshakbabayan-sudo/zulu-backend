<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table): void {
            $table->string('visibility_rule')->default('show_all');
        });

        Schema::table('hotels', function (Blueprint $table): void {
            $table->string('visibility_rule')->default('show_all');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('visibility_rule')->default('show_all');
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table): void {
            $table->dropColumn('visibility_rule');
        });

        Schema::table('hotels', function (Blueprint $table): void {
            $table->dropColumn('visibility_rule');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn('visibility_rule');
        });
    }
};
