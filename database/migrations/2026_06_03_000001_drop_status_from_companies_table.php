<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the dead `companies.status` column.
     *
     * `companies.status` was hardcoded to 'active' at company creation
     * (CompanyApplicationApprovalService) and never updated anywhere else —
     * there is no setter, route, or UI for it. The real company lifecycle
     * field is `governance_status` (pending/active/suspended/rejected).
     * Because both defaulted to 'active', the admin Companies list rendered
     * two identical "Active" columns ("Status" + "Governance"). Per the
     * 2026-06-03 admin cleanup principle (delete unused columns, don't hide),
     * the dead column is removed.
     *
     * TWO indexes reference `status` and must be dropped first (SQLite rebuilds
     * the table on dropColumn and re-validates every index):
     *   - companies_status_index            (status)               2026_04_03
     *   - companies_partner_visibility_idx  (is_partner_visible,type,status) 2026_05_13
     * The single-column status index is simply dropped (governance_status has
     * its own companies_governance_status_index). The composite index is
     * recreated on the REAL lifecycle column (governance_status).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'status')) {
            return;
        }

        if (Schema::hasIndex('companies', 'companies_partner_visibility_idx')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropIndex('companies_partner_visibility_idx');
            });
        }

        if (Schema::hasIndex('companies', 'companies_status_index')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropIndex('companies_status_index');
            });
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        if (! Schema::hasIndex('companies', 'companies_partner_visibility_idx')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->index(['is_partner_visible', 'type', 'governance_status'], 'companies_partner_visibility_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'status')) {
            return;
        }

        if (Schema::hasIndex('companies', 'companies_partner_visibility_idx')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropIndex('companies_partner_visibility_idx');
            });
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('type');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->index(['is_partner_visible', 'type', 'status'], 'companies_partner_visibility_idx');
        });

        if (! Schema::hasIndex('companies', 'companies_status_index')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->index('status', 'companies_status_index');
            });
        }
    }
};
