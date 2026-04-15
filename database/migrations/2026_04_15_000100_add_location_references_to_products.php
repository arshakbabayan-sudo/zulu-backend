<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('city')
                ->constrained('locations')
                ->nullOnDelete();
        });

        Schema::table('flights', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('arrival_city')
                ->constrained('locations')
                ->nullOnDelete();
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('dropoff_location')
                ->constrained('locations')
                ->nullOnDelete();
        });

        Schema::table('visas', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('country_id')
                ->constrained('locations')
                ->nullOnDelete();
        });

        Schema::table('excursions', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('city')
                ->constrained('locations')
                ->nullOnDelete();
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('origin_location_id')
                ->nullable()
                ->after('pickup_city')
                ->constrained('locations')
                ->nullOnDelete();

            $table->foreignId('destination_location_id')
                ->nullable()
                ->after('dropoff_city')
                ->constrained('locations')
                ->nullOnDelete();
        });

        Schema::create('excursion_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('excursion_id')->constrained('excursions')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['excursion_id', 'location_id']);
            $table->index(['location_id', 'excursion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excursion_location');

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_location_id');
            $table->dropConstrainedForeignId('destination_location_id');
        });

        Schema::table('excursions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('visas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('flights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};

