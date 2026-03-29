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
        Schema::table('approvals', function (Blueprint $table) {
            $table->foreignId('requested_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['entity_type', 'entity_id'], 'approvals_entity_lookup_index');
        });

        Schema::table('statuses', function (Blueprint $table) {
            $table->unique(['entity_type', 'code'], 'statuses_entity_type_code_unique');
        });

        Schema::table('flights', function (Blueprint $table) {
            $table->unique('offer_id', 'flights_offer_id_unique');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->unique('offer_id', 'hotels_offer_id_unique');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->unique('offer_id', 'transfers_offer_id_unique');
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->unique('offer_id', 'cars_offer_id_unique');
        });

        Schema::table('excursions', function (Blueprint $table) {
            $table->unique('offer_id', 'excursions_offer_id_unique');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unique('offer_id', 'packages_offer_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique('packages_offer_id_unique');
        });

        Schema::table('excursions', function (Blueprint $table) {
            $table->dropUnique('excursions_offer_id_unique');
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->dropUnique('cars_offer_id_unique');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropUnique('transfers_offer_id_unique');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropUnique('hotels_offer_id_unique');
        });

        Schema::table('flights', function (Blueprint $table) {
            $table->dropUnique('flights_offer_id_unique');
        });

        Schema::table('statuses', function (Blueprint $table) {
            $table->dropUnique('statuses_entity_type_code_unique');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex('approvals_entity_lookup_index');
            $table->dropConstrainedForeignId('requested_by');
        });
    }
};
