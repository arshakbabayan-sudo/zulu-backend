<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RBAC #2 Part Բ — remove the redundant `operator_admin` role (5 roles → 4).
 *
 * Arshak's model has exactly 4 roles: Super admin, ZULU staff (platform_admin),
 * Operator (company_admin), Agent (agent). `operator_admin` was an internal
 * "director under the owner" rank that never got real users (0 members) and is
 * a permission-duplicate of company_admin — Arshak asked to delete it outright
 * (the "+ New role" button remains if a manager tier is wanted later).
 *
 * SAFE-GUARD: only deletes when the role has 0 memberships, so a live operator
 * can never be orphaned by the user_company cascade. The canonical
 * "operator-tier" alias inside AdminAccessService is intentionally LEFT in
 * place — company_admin still maps to it (it labels the tier, not this row).
 *
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'operator_admin')->first();
        if ($role === null) {
            return;
        }

        $members = DB::table('user_company')->where('role_id', $role->id)->count();
        if ($members > 0) {
            Log::warning("Skipping operator_admin deletion — {$members} member(s) still assigned; reassign them first.");
            return;
        }

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        DB::table('roles')->where('id', $role->id)->delete();
    }

    public function down(): void
    {
        // Not reversible — recreating an empty redundant role serves no purpose.
    }
};
