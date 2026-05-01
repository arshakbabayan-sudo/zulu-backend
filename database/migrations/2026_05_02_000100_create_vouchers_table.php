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

        Schema::create('vouchers', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->string('voucher_number')->unique();

            $table->uuid('order_id');
            $table->uuid('order_item_id');

            $table->string('service_type');
            $table->unsignedBigInteger('issuer_company_id')->nullable();

            $table->string('holder_name');
            $table->string('holder_passport')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('passenger_data')->nullable()
                : $table->json('passenger_data')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('service_snapshot')
                : $table->json('service_snapshot');

            $table->string('qr_token', 191)->unique();
            $table->string('qr_image_url')->nullable();
            $table->string('pdf_url')->nullable();

            $table->string('status')->default('issued');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('used_by_ip', 45)->nullable();
            $table->unsignedInteger('verification_count')->default(0);

            $table->uuid('reissued_from_id')->nullable();

            $table->string('language', 5)->default('en');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('issuer_company_id')->references('id')->on('companies')->nullOnDelete();

            $table->index('order_id');
            $table->index('order_item_id');
            $table->index('issuer_company_id');
            $table->index('status');
            $table->index('service_type');
            $table->index(['valid_from', 'valid_to']);
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->foreign('reissued_from_id')->references('id')->on('vouchers')->nullOnDelete();
            $table->index('reissued_from_id');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_service_type_check CHECK (service_type IN ('flight','hotel','transfer','car','excursion','visa','insurance','package'))");
            DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_status_check CHECK (status IN ('issued','used','void','reissued','expired'))");
            DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_language_check CHECK (language IN ('hy','en','ru'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
