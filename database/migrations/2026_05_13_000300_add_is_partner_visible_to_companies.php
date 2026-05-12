<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        if (! Schema::hasColumn('companies', 'is_partner_visible')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('is_partner_visible')->default(false)->after('logo');
                $table->index(['is_partner_visible', 'type', 'status'], 'companies_partner_visibility_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        if (Schema::hasColumn('companies', 'is_partner_visible')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropIndex('companies_partner_visibility_idx');
                $table->dropColumn('is_partner_visible');
            });
        }
    }
};
