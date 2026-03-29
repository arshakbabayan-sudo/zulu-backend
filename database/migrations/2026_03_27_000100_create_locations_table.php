<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 2)->unique();
            $table->string('flag_emoji', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['country_id', 'name']);
        });

        Schema::create('cities', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('countries');
    }
};

