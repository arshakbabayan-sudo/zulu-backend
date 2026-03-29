<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Register transfer write permissions for existing databases (aligned with RbacBootstrapSeeder).
     */
    public function up(): void
    {
        $ids = [];
        foreach (['transfers.create', 'transfers.update', 'transfers.delete'] as $name) {
            $ids[] = Permission::query()->firstOrCreate(['name' => $name])->id;
        }

        foreach (Role::query()->get() as $role) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        $names = ['transfers.create', 'transfers.update', 'transfers.delete'];
        $permIds = Permission::query()->whereIn('name', $names)->pluck('id');

        foreach (Role::query()->get() as $role) {
            $role->permissions()->detach($permIds);
        }

        Permission::query()->whereIn('name', $names)->delete();
    }
};
