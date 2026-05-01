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

        Schema::create('contract_templates', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type'); // platform | partner_supplier | partner_reseller | partner_default
            $table->string('language', 5)->default('en');
            $table->unsignedInteger('version')->default(1);
            $table->text('body');
            $driver === 'pgsql'
                ? $table->jsonb('variables')->nullable()
                : $table->json('variables')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['type', 'language', 'active']);
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE contract_templates ADD CONSTRAINT contract_templates_type_check CHECK (type IN ('platform','partner_supplier','partner_reseller','partner_default'))");
            DB::statement("ALTER TABLE contract_templates ADD CONSTRAINT contract_templates_language_check CHECK (language IN ('hy','en','ru'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
