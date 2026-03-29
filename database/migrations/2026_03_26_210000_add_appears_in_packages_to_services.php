<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table): void {
            if (! Schema::hasColumn('flights', 'appears_in_packages')) {
                $table->boolean('appears_in_packages')->default(true)->after('is_package_eligible');
            }
        });

        Schema::table('hotels', function (Blueprint $table): void {
            if (! Schema::hasColumn('hotels', 'appears_in_packages')) {
                $table->boolean('appears_in_packages')->default(true)->after('is_package_eligible');
            }
        });

        Schema::table('transfers', function (Blueprint $table): void {
            if (! Schema::hasColumn('transfers', 'appears_in_packages')) {
                $table->boolean('appears_in_packages')->default(true)->after('is_package_eligible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table): void {
            if (Schema::hasColumn('flights', 'appears_in_packages')) {
                $table->dropColumn('appears_in_packages');
            }
        });

        Schema::table('hotels', function (Blueprint $table): void {
            if (Schema::hasColumn('hotels', 'appears_in_packages')) {
                $table->dropColumn('appears_in_packages');
            }
        });

        Schema::table('transfers', function (Blueprint $table): void {
            if (Schema::hasColumn('transfers', 'appears_in_packages')) {
                $table->dropColumn('appears_in_packages');
            }
        });
    }
};
