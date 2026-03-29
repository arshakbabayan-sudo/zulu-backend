<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Register flight module permissions for existing databases (aligned with RbacBootstrapSeeder).
     */
    public function up(): void
    {
        $ids = [];
        foreach (['flights.view', 'flights.create', 'flights.update', 'flights.delete'] as $name) {
            $ids[] = Permission::query()->firstOrCreate(['name' => $name])->id;
        }

        foreach (Role::query()->get() as $role) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $names = ['flights.view', 'flights.create', 'flights.update', 'flights.delete'];
        $permIds = Permission::query()->whereIn('name', $names)->pluck('id');

        foreach (Role::query()->get() as $role) {
            $role->permissions()->detach($permIds);
        }

        Permission::query()->whereIn('name', $names)->delete();
    }
};
