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
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'visibility_rule')) {
                $table->string('visibility_rule')->default('show_all')->after('is_package_eligible');
            }
            if (! Schema::hasColumn('packages', 'popularity_score')) {
                $table->integer('popularity_score')->default(0)->after('visibility_rule');
            }
            if (! Schema::hasColumn('packages', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('popularity_score');
            }
            if (! Schema::hasColumn('packages', 'component_count')) {
                $table->unsignedInteger('component_count')->default(0)->after('is_featured');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'component_count')) {
                $table->dropColumn('component_count');
            }
            if (Schema::hasColumn('packages', 'is_featured')) {
                $table->dropColumn('is_featured');
            }
            if (Schema::hasColumn('packages', 'popularity_score')) {
                $table->dropColumn('popularity_score');
            }
            if (Schema::hasColumn('packages', 'visibility_rule')) {
                $table->dropColumn('visibility_rule');
            }
        });
    }
};
