<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC #2 Part Գ — editable role display name.
 *
 * The internal role `name` is a code slug (company_admin, agent, …) referenced
 * throughout the code, so it must stay stable. But Arshak needs to rename what
 * he SEES (e.g. the wrong "Operator/Agent (owner)" → "Operator"). This adds a
 * separate, free-text `display_name` the super-admin can edit from the RBAC UI;
 * the slug is shown read-only. When `display_name` is null the UI falls back to
 * the localized label map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
