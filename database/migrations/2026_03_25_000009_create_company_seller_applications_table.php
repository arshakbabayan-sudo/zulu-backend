<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_seller_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('service_type', 64);
            $table->string('status', 32)->default('pending');
            // pending, under_review, approved, rejected
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable(); // internal admin notes
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One active application per service type per company
            $table->unique(['company_id', 'service_type']);
            $table->index('status');
            $table->index('company_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'is_airline')) {
                $table->boolean('is_airline')->default(false)->after('is_seller');
                // Airline flag: allows selling flights cross-country (exception to geo-restriction)
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('company_seller_applications')) {
            Schema::dropIfExists('company_seller_applications');
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'is_airline')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('is_airline');
            });
        }
    }
};

