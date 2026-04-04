<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAR rental Step C3 — visibility_rule + appearance flags (mirrors transfers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            if (! Schema::hasColumn('cars', 'visibility_rule')) {
                $after = Schema::hasColumn('cars', 'advanced_options') ? 'advanced_options' : 'availability_status';
                $table->string('visibility_rule')->default('show_all')->after($after);
            }
            if (! Schema::hasColumn('cars', 'appears_in_web')) {
                $table->boolean('appears_in_web')->default(true)->after('visibility_rule');
            }
            if (! Schema::hasColumn('cars', 'appears_in_admin')) {
                $table->boolean('appears_in_admin')->default(true)->after('appears_in_web');
            }
            if (! Schema::hasColumn('cars', 'appears_in_zulu_admin')) {
                $table->boolean('appears_in_zulu_admin')->default(true)->after('appears_in_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            if (Schema::hasColumn('cars', 'appears_in_zulu_admin')) {
                $table->dropColumn('appears_in_zulu_admin');
            }
            if (Schema::hasColumn('cars', 'appears_in_admin')) {
                $table->dropColumn('appears_in_admin');
            }
            if (Schema::hasColumn('cars', 'appears_in_web')) {
                $table->dropColumn('appears_in_web');
            }
            if (Schema::hasColumn('cars', 'visibility_rule')) {
                $table->dropColumn('visibility_rule');
            }
        });
    }
};
