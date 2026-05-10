<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['flights', 'cars', 'transfers', 'excursions', 'packages'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                if (! Schema::hasColumn($t->getTable(), 'short_description')) {
                    $t->text('short_description')->nullable();
                }
                if (! Schema::hasColumn($t->getTable(), 'main_image')) {
                    $t->string('main_image')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                if (Schema::hasColumn($t->getTable(), 'main_image')) {
                    $t->dropColumn('main_image');
                }
                if (Schema::hasColumn($t->getTable(), 'short_description')) {
                    $t->dropColumn('short_description');
                }
            });
        }
    }
};
