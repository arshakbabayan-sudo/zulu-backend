<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXCURSIONS Step C1 — content blocks + photos + price-by-dates (JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excursions', function (Blueprint $table) {
            $table->json('includes')->nullable()->after('is_bookable');
            $table->text('meeting_pickup')->nullable()->after('includes');
            $table->text('additional_info')->nullable()->after('meeting_pickup');
            $table->text('cancellation_policy')->nullable()->after('additional_info');
            $table->json('photos')->nullable()->after('cancellation_policy');
            $table->json('price_by_dates')->nullable()->after('photos');
        });
    }

    public function down(): void
    {
        Schema::table('excursions', function (Blueprint $table) {
            $table->dropColumn([
                'includes',
                'meeting_pickup',
                'additional_info',
                'cancellation_policy',
                'photos',
                'price_by_dates',
            ]);
        });
    }
};
