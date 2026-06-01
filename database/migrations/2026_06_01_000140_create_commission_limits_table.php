<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-configurable commission % bounds per service type (roadmap P0-2 / item 1.2.3).
 *
 * The 3-step profile-completion wizard's "Commission" step lets an operator set their
 * own commission % per service type; that input is validated against these bounds.
 * service_type === '*' is the global fallback used when no per-type row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_limits')) {
            Schema::create('commission_limits', function (Blueprint $table): void {
                $table->id();
                // '*' = global default; otherwise hotel|flight|car|transfer|excursion|visa|insurance|package
                $table->string('service_type', 64)->unique();
                $table->decimal('min_percent', 5, 2)->default(0);
                $table->decimal('max_percent', 5, 2)->default(100);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Seed the global default (0–100) so existing behaviour is preserved.
        $exists = DB::table('commission_limits')->where('service_type', '*')->exists();
        if (! $exists) {
            DB::table('commission_limits')->insert([
                'service_type' => '*',
                'min_percent' => 0,
                'max_percent' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_limits');
    }
};
