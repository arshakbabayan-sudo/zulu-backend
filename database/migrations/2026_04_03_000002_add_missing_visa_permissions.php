<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['visas.update', 'visas.delete'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name]);
        }

        // Attach the new permissions to the roles that manage commerce inventory.
        // Without this step an existing DB (migrate-only, no re-seed) would have the
        // permission rows but no role_permission pivot rows, leaving operators with 403
        // on PATCH /visas/{id} and DELETE /visas/{id}.
        $permissionIds = Permission::query()
            ->whereIn('name', ['visas.update', 'visas.delete'])
            ->pluck('id');

        Role::query()
            ->whereIn('name', ['super_admin', 'company_admin', 'operator_admin'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds));
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', ['visas.update', 'visas.delete'])
            ->delete();
    }
};
