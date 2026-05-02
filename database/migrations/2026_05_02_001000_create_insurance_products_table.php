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

        Schema::create('insurance_products', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('underwriter_name');
            $table->string('underwriter_license_ref')->nullable();
            $table->string('product_name');
            $table->string('coverage_territory')->default('worldwide'); // schengen | europe | worldwide | custom

            $driver === 'pgsql'
                ? $table->jsonb('covered_countries')->nullable()
                : $table->json('covered_countries')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('excluded_countries')->nullable()
                : $table->json('excluded_countries')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('coverage_details')
                : $table->json('coverage_details');

            // [{min_age, max_age, multiplier}, ...]
            $driver === 'pgsql'
                ? $table->jsonb('age_tiers')
                : $table->json('age_tiers');

            $table->decimal('base_premium', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');

            // [7, 14, 30, 60, 365]
            $driver === 'pgsql'
                ? $table->jsonb('duration_options')
                : $table->json('duration_options');

            $table->boolean('pre_existing_covered')->default(false);

            $driver === 'pgsql'
                ? $table->jsonb('sports_coverage')->nullable()
                : $table->json('sports_coverage')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('exclusions')->nullable()
                : $table->json('exclusions')->nullable();

            $table->string('policy_template_url')->nullable();
            $table->string('status')->default('active'); // active | inactive | archived
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $table->index('company_id');
            $table->index('status');
            $table->index('coverage_territory');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE insurance_products ADD CONSTRAINT insurance_products_status_check CHECK (status IN ('active','inactive','archived'))");
            DB::statement("ALTER TABLE insurance_products ADD CONSTRAINT insurance_products_territory_check CHECK (coverage_territory IN ('schengen','europe','worldwide','custom'))");
        }

        Schema::create('insurance_policies', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->string('policy_number')->unique();
            $table->unsignedBigInteger('product_id');
            $table->uuid('order_id')->nullable();
            $table->uuid('order_item_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // [{name, dob, passport, age_at_issue}]
            $driver === 'pgsql'
                ? $table->jsonb('insured_persons')
                : $table->json('insured_persons');

            $table->date('coverage_start_date');
            $table->date('coverage_end_date');
            $table->unsignedInteger('coverage_days');
            $table->decimal('premium_paid', 12, 2);
            $table->string('currency', 3);

            // Snapshot of product details at issuance (immutable record)
            $driver === 'pgsql'
                ? $table->jsonb('product_snapshot')
                : $table->json('product_snapshot');

            $table->string('status')->default('active'); // active | cancelled | expired | claimed

            $table->string('policy_pdf_url')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('insurance_products')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index('product_id');
            $table->index('order_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('coverage_start_date');
            $table->index('coverage_end_date');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE insurance_policies ADD CONSTRAINT insurance_policies_status_check CHECK (status IN ('active','cancelled','expired','claimed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policies');
        Schema::dropIfExists('insurance_products');
    }
};
