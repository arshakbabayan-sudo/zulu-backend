<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.12 — Service catalog.
 *
 * Generic bookable services that don't fit the standard inventory verticals
 * (hotels / flights / cars / etc.). Examples: airport meet-and-greet, custom
 * itinerary planning, luggage storage, late check-in fees. Composite offers
 * + homepage surfacing happen as follow-up wiring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('category', 64)->nullable();
            $table->text('description')->nullable();
            $table->decimal('base_price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('unit', 32)->nullable(); // per_person | per_group | flat | per_hour | per_day
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_catalog_items');
    }
};
