<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds latitude/longitude to module tables that don't already carry them
     * (transfers already has pickup/dropoff coords; excursions, cars, packages
     * need a single point; flights are skipped — a point on the globe is not
     * meaningful for a flight route).
     */
    private array $tables = ['cars', 'excursions', 'packages'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                if (! Schema::hasColumn($t->getTable(), 'latitude')) {
                    $t->decimal('latitude', 10, 8)->nullable();
                }
                if (! Schema::hasColumn($t->getTable(), 'longitude')) {
                    $t->decimal('longitude', 11, 8)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                if (Schema::hasColumn($t->getTable(), 'longitude')) {
                    $t->dropColumn('longitude');
                }
                if (Schema::hasColumn($t->getTable(), 'latitude')) {
                    $t->dropColumn('latitude');
                }
            });
        }
    }
};
