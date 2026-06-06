<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC #2 (redo) cleanup — drop the legacy `menu.*` permission set.
 *
 * The first attempt added a separate `menu.<group>.view` permission set + a
 * duplicate "Left menu (visibility)" tree section. That was replaced by the
 * per-section `<section>.view` gate integrated into each menu section (migration
 * 2026_06_06_000040). The frontend + tree no longer reference `menu.*`, so it is
 * now dead — remove it (and any lingering role grants) to avoid a confusing
 * orphaned permission set in the RBAC UI/audit.
 *
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = Permission::query()->where('name', 'like', 'menu.%')->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Not reversible — the menu.* set was a mistake; the section.view gates
        // (migration 2026_06_06_000040) supersede it.
    }
};
