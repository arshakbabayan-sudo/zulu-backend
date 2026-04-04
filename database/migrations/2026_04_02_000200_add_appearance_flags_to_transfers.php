<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            if (! Schema::hasColumn('transfers', 'appears_in_web')) {
                $table->boolean('appears_in_web')->default(true)->after('visibility_rule');
            }
            if (! Schema::hasColumn('transfers', 'appears_in_admin')) {
                $table->boolean('appears_in_admin')->default(true)->after('appears_in_web');
            }
            if (! Schema::hasColumn('transfers', 'appears_in_zulu_admin')) {
                $table->boolean('appears_in_zulu_admin')->default(true)->after('appears_in_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            if (Schema::hasColumn('transfers', 'appears_in_zulu_admin')) {
                $table->dropColumn('appears_in_zulu_admin');
            }
            if (Schema::hasColumn('transfers', 'appears_in_admin')) {
                $table->dropColumn('appears_in_admin');
            }
            if (Schema::hasColumn('transfers', 'appears_in_web')) {
                $table->dropColumn('appears_in_web');
            }
        });
    }
};
