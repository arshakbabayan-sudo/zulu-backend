<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Register car and excursion commerce permissions and attach them to company_admin.
     */
    public function up(): void
    {
        $names = [
            'cars.view',
            'cars.create',
            'cars.update',
            'cars.delete',
            'excursions.view',
            'excursions.create',
            'excursions.update',
            'excursions.delete',
        ];

        $ids = [];
        foreach ($names as $name) {
            $ids[] = Permission::query()->firstOrCreate(['name' => $name])->id;
        }

        $role = Role::query()->where('name', 'company_admin')->first();
        if ($role !== null) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $names = [
            'cars.view',
            'cars.create',
            'cars.update',
            'cars.delete',
            'excursions.view',
            'excursions.create',
            'excursions.update',
            'excursions.delete',
        ];

        $permIds = Permission::query()->whereIn('name', $names)->pluck('id');
        $role = Role::query()->where('name', 'company_admin')->first();
        if ($role !== null) {
            $role->permissions()->detach($permIds);
        }

        Permission::query()->whereIn('name', $names)->delete();
    }
};
