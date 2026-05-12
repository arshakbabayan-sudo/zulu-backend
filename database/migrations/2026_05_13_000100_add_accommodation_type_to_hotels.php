<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED = ['hotel', 'apartment', 'villa', 'hostel', 'guesthouse'];

    public function up(): void
    {
        if (! Schema::hasTable('hotels')) {
            return;
        }

        if (! Schema::hasColumn('hotels', 'accommodation_type')) {
            Schema::table('hotels', function (Blueprint $table) {
                $table->string('accommodation_type', 32)->default('hotel')->after('hotel_type');
                $table->index('accommodation_type', 'hotels_accommodation_type_index');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $allowed = "'".implode("','", self::ALLOWED)."'";
            DB::statement(
                'ALTER TABLE hotels DROP CONSTRAINT IF EXISTS hotels_accommodation_type_check'
            );
            DB::statement(
                'ALTER TABLE hotels ADD CONSTRAINT hotels_accommodation_type_check '
                ."CHECK (accommodation_type IN ({$allowed}))"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hotels')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hotels DROP CONSTRAINT IF EXISTS hotels_accommodation_type_check');
        }

        if (Schema::hasColumn('hotels', 'accommodation_type')) {
            Schema::table('hotels', function (Blueprint $table) {
                $table->dropIndex('hotels_accommodation_type_index');
                $table->dropColumn('accommodation_type');
            });
        }
    }
};
