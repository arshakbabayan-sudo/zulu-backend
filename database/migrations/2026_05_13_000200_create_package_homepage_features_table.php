<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED_SECTIONS = ['special_offers', 'popular_destinations'];

    public function up(): void
    {
        if (Schema::hasTable('package_homepage_features')) {
            return;
        }

        Schema::create('package_homepage_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('section_slug', 64);
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['package_id', 'section_slug'], 'pkg_homepage_features_pkg_section_unique');
            $table->index(['section_slug', 'is_active', 'position'], 'pkg_homepage_features_section_active_pos_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $allowed = "'".implode("','", self::ALLOWED_SECTIONS)."'";
            DB::statement(
                'ALTER TABLE package_homepage_features DROP CONSTRAINT IF EXISTS pkg_homepage_features_section_check'
            );
            DB::statement(
                'ALTER TABLE package_homepage_features ADD CONSTRAINT pkg_homepage_features_section_check '
                ."CHECK (section_slug IN ({$allowed}))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE package_homepage_features DROP CONSTRAINT IF EXISTS pkg_homepage_features_section_check');
        }

        Schema::dropIfExists('package_homepage_features');
    }
};
