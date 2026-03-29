<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Register hotels.view for existing databases (aligned with RbacBootstrapSeeder).
     */
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'hotels.view']);

        foreach (Role::query()->get() as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $perm = Permission::query()->where('name', 'hotels.view')->first();
        if ($perm === null) {
            return;
        }

        foreach (Role::query()->get() as $role) {
            $role->permissions()->detach([$perm->id]);
        }

        $perm->delete();
    }
};
