<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('contracts', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->string('contract_number')->unique();

            $table->string('type'); // platform | partner

            // party_a NULL for Platform Contract (ZULU side); both filled for Partner Agreement
            $table->unsignedBigInteger('party_a_company_id')->nullable();
            $table->unsignedBigInteger('party_b_company_id');

            $table->uuid('template_id');

            $table->string('status')->default('draft');

            $driver === 'pgsql'
                ? $table->jsonb('commission_clause')
                : $table->json('commission_clause');

            $driver === 'pgsql'
                ? $table->jsonb('payment_terms')
                : $table->json('payment_terms');

            $driver === 'pgsql'
                ? $table->jsonb('cancellation_policy')->nullable()
                : $table->json('cancellation_policy')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('rendered_body')
                : $table->json('rendered_body');

            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->unsignedInteger('termination_notice_days')->default(30);

            $table->string('signed_pdf_url')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('signatures')->nullable()
                : $table->json('signatures')->nullable();

            $table->text('termination_reason')->nullable();
            $table->timestamp('terminated_at')->nullable();

            $table->unsignedBigInteger('created_by_user_id');

            $table->string('language', 5)->default('en');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('party_a_company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('party_b_company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('template_id')->references('id')->on('contract_templates')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->index('party_a_company_id');
            $table->index('party_b_company_id');
            $table->index('status');
            $table->index('type');
            $table->index('template_id');
            $table->index('expiry_date');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_type_check CHECK (type IN ('platform','partner'))");
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_status_check CHECK (status IN ('draft','sent','signed_by_a','signed_by_b','countersigned','active','expired','terminated','disputed'))");
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_language_check CHECK (language IN ('hy','en','ru'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
