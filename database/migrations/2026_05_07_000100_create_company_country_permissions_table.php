<?php

use App\Services\Infrastructure\GeoRestrictionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-country seller permissions.
 *
 * Until now `companies.country` was a single value compared 1:1 against
 * the service country in {@see GeoRestrictionService}.
 * That blocked legitimate operators with multi-country licenses (e.g.
 * an Armenia-registered tour operator who also has rights to sell
 * Russia / UAE packages).
 *
 * This table mirrors the existing `company_seller_permissions` table
 * (which gates per-service-type), but for countries. Super admin grants
 * an operator the right to sell services in a specific country; the
 * geo check passes if the service country matches either the company's
 * registered country OR has an active row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_country_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('country_code', 8); // ISO-2 code (AM, RU, AE, ...) — uppercase
            $table->string('country_name', 64); // human-readable (Armenia, Russia, ...) — display copy
            $table->string('status', 32)->default('active'); // active | revoked
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'country_code']);
            $table->index('company_id');
            $table->index(['status', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_country_permissions');
    }
};
