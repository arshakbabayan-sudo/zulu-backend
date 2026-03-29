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
        Schema::table('package_components', function (Blueprint $table) {
            if (! Schema::hasColumn('package_components', 'service_type')) {
                $table->string('service_type', 32)->nullable()->after('offer_id');
            }
            if (! Schema::hasColumn('package_components', 'service_id')) {
                $table->unsignedBigInteger('service_id')->nullable()->after('service_type');
            }
            if (! Schema::hasColumn('package_components', 'notes')) {
                $table->text('notes')->nullable()->after('is_required');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_components', function (Blueprint $table) {
            if (Schema::hasColumn('package_components', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('package_components', 'service_id')) {
                $table->dropColumn('service_id');
            }
            if (Schema::hasColumn('package_components', 'service_type')) {
                $table->dropColumn('service_type');
            }
        });
    }
};
