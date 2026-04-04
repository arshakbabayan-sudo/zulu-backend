<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration summary (CAR rental Step B1 — core mapping):
 * Adds nullable vehicle identity, capacity, availability window, pricing, and status columns on `cars`.
 * All columns are nullable so existing rows and API flows remain valid; backfill can be done later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->string('vehicle_type')->nullable()->after('vehicle_class');
            $table->string('brand')->nullable()->after('vehicle_type');
            $table->string('model')->nullable()->after('brand');
            $table->unsignedSmallInteger('year')->nullable()->after('model');

            $table->string('transmission_type')->nullable()->after('year');
            $table->string('fuel_type')->nullable()->after('transmission_type');

            $table->string('fleet')->nullable()->after('fuel_type');
            $table->string('category')->nullable()->after('fleet');

            $table->unsignedTinyInteger('seats')->nullable()->after('category');
            $table->unsignedTinyInteger('suitcases')->nullable()->after('seats');
            $table->unsignedTinyInteger('small_bag')->nullable()->after('suitcases');

            $table->dateTime('availability_window_start')->nullable()->after('small_bag');
            $table->dateTime('availability_window_end')->nullable()->after('availability_window_start');

            $table->string('pricing_mode')->nullable()->after('availability_window_end');
            $table->decimal('base_price', 12, 2)->nullable()->after('pricing_mode');

            $table->string('status')->nullable()->after('base_price');
            $table->string('availability_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn([
                'vehicle_type',
                'brand',
                'model',
                'year',
                'transmission_type',
                'fuel_type',
                'fleet',
                'category',
                'seats',
                'suitcases',
                'small_bag',
                'availability_window_start',
                'availability_window_end',
                'pricing_mode',
                'base_price',
                'status',
                'availability_status',
            ]);
        });
    }
};
