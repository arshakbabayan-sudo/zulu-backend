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
        if (! Schema::hasTable('commissions')) {
            Schema::create('commissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id');
                $table->string('service_type', 64);
                $table->enum('commission_type', ['percentage', 'fixed'])->default('percentage');
                $table->decimal('value', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            $foreignKeys = array_column(Schema::getForeignKeys('commissions'), 'name');
            if (! in_array('commissions_company_id_foreign', $foreignKeys, true)) {
                Schema::table('commissions', function (Blueprint $table): void {
                    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                });
            }

            return;
        }

        Schema::table('commissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('commissions', 'service_type')) {
                $table->string('service_type', 64)->default('air_ticket')->after('company_id');
            }

            if (! Schema::hasColumn('commissions', 'commission_type')) {
                $table->enum('commission_type', ['percentage', 'fixed'])->default('percentage')->after('service_type');
            }

            if (! Schema::hasColumn('commissions', 'value')) {
                $table->decimal('value', 10, 2)->default(0)->after('commission_type');
            }

            if (! Schema::hasColumn('commissions', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('value');
            }
        });

        if (Schema::hasColumn('commissions', 'percent') && Schema::hasColumn('commissions', 'value')) {
            DB::table('commissions')
                ->whereNull('value')
                ->orWhere('value', '=', 0)
                ->update([
                    'value' => DB::raw('percent'),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('commissions')) {
            return;
        }

        Schema::table('commissions', function (Blueprint $table): void {
            if (Schema::hasColumn('commissions', 'is_active')) {
                $table->dropColumn('is_active');
            }

            if (Schema::hasColumn('commissions', 'value')) {
                $table->dropColumn('value');
            }

            if (Schema::hasColumn('commissions', 'commission_type')) {
                $table->dropColumn('commission_type');
            }
        });
    }
};
