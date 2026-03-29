<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('approvals')) {
            Schema::table('approvals', function (Blueprint $table): void {
                if (! Schema::hasColumn('approvals', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable();
                }
                if (! Schema::hasColumn('approvals', 'reviewed_by')) {
                    $table->foreignId('reviewed_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('approvals', 'priority')) {
                    $table->string('priority', 32)->nullable()->default('normal');
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach ([
                'platform.companies.list',
                'platform.companies.governance',
                'platform.approvals.list',
                'platform.approvals.manage',
                'platform.orders.list',
                'platform.payments.list',
                'platform.packages.moderate',
                'platform.stats.view',
            ] as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'platform.companies.list',
                'platform.companies.governance',
                'platform.approvals.list',
                'platform.approvals.manage',
                'platform.orders.list',
                'platform.payments.list',
                'platform.packages.moderate',
                'platform.stats.view',
            ])->delete();
        }

        if (Schema::hasTable('approvals')) {
            Schema::table('approvals', function (Blueprint $table): void {
                if (Schema::hasColumn('approvals', 'reviewed_by')) {
                    $table->dropForeign(['reviewed_by']);
                }
            });

            Schema::table('approvals', function (Blueprint $table): void {
                foreach (['priority', 'reviewed_at', 'reviewed_by'] as $col) {
                    if (Schema::hasColumn('approvals', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
