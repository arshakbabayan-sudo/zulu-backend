<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXCURSIONS Step C3 — visibility_rule + appearance flags (mirrors cars/transfers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excursions', function (Blueprint $table): void {
            if (! Schema::hasColumn('excursions', 'visibility_rule')) {
                $after = Schema::hasColumn('excursions', 'price_by_dates') ? 'price_by_dates' : 'is_bookable';
                $table->string('visibility_rule')->default('show_all')->after($after);
            }
            if (! Schema::hasColumn('excursions', 'appears_in_web')) {
                $table->boolean('appears_in_web')->default(true)->after('visibility_rule');
            }
            if (! Schema::hasColumn('excursions', 'appears_in_admin')) {
                $table->boolean('appears_in_admin')->default(true)->after('appears_in_web');
            }
            if (! Schema::hasColumn('excursions', 'appears_in_zulu_admin')) {
                $table->boolean('appears_in_zulu_admin')->default(true)->after('appears_in_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('excursions', function (Blueprint $table): void {
            if (Schema::hasColumn('excursions', 'appears_in_zulu_admin')) {
                $table->dropColumn('appears_in_zulu_admin');
            }
            if (Schema::hasColumn('excursions', 'appears_in_admin')) {
                $table->dropColumn('appears_in_admin');
            }
            if (Schema::hasColumn('excursions', 'appears_in_web')) {
                $table->dropColumn('appears_in_web');
            }
            if (Schema::hasColumn('excursions', 'visibility_rule')) {
                $table->dropColumn('visibility_rule');
            }
        });
    }
};
