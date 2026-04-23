<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 Step 1b — Drop legacy countries/regions/cities tables per ADR-004.
 *
 * ADR-004 migration strategy item 5 requires removing legacy location tables
 * after moving reads/writes to the unified `locations` tree.
 *
 * References:
 * - docs/decisions/ADR-004-location-tree.md
 * - docs/SPRINT_LOG.md (Sprint 1, Step 1b)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Children-first order to avoid FK constraint failures.
        Schema::dropIfExists('cities');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('countries');
    }

    public function down(): void
    {
        if (! Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code', 2)->unique();
                $table->string('flag_emoji', 16)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('regions')) {
            Schema::create('regions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 32)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['country_id', 'name']);
            });
        }

        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
                $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->timestamps();

                $table->index(['region_id', 'name']);
                $table->index(['country_id', 'name']);
            });
        }
    }
};
