<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Database\Seeder;

class RbacBootstrapSeeder extends Seeder
{
    /** Post-build bootstrap: primary super admin (password only defined here). */
    private const PRIMARY_SUPER_ADMIN_EMAIL = 'arshakbabayan@gmail.com';

    private const PRIMARY_SUPER_ADMIN_PASSWORD = 'Zefora992311@!&?';

    /**
     * Minimal RBAC + one tenant so migrate:fresh --seed yields a login-ready operator.
     *
     * @var list<string>
     */
    private const PERMISSION_NAMES = [
        'companies.view',
        'companies.edit_profile',
        'companies.view_dashboard',
        'companies.manage_seller_permissions',
        'seller_permissions.view',
        'offers.view',
        'offers.create',
        'offers.publish',
        'offers.archive',
        'bookings.view',
        'bookings.create',
        'bookings.confirm',
        'bookings.cancel',
        'invoices.view',
        'invoices.create',
        'invoices.issue',
        'invoices.pay',
        'invoices.cancel',
        'payments.view',
        'payments.create',
        'payments.pay',
        'payments.capture',
        'payments.fail',
        'payments.refund',
        'commissions.view',
        'commissions.create',
        'commissions.update',
        'commissions.manage',
        'commission_records.view',
        'finance.entitlements.view',
        'finance.entitlements.manage',
        'finance.settlements.view',
        'finance.settlements.manage',
        'visas.view',
        'visas.create',
        'visas.update',
        'visas.delete',
        'cars.view',
        'cars.create',
        'cars.update',
        'cars.delete',
        'excursions.view',
        'excursions.create',
        'excursions.update',
        'excursions.delete',
        'flights.view',
        'flights.create',
        'flights.update',
        'flights.delete',
        'hotels.view',
        'hotels.create',
        'hotels.update',
        'hotels.delete',
        'transfers.view',
        'transfers.create',
        'transfers.update',
        'transfers.delete',
        'packages.view',
        'packages.create',
        'packages.edit',
        'packages.delete',
        'packages.manage_components',
        'package_orders.view',
        'package_orders.manage',
        'account.update_profile',
        'saved_items.manage',
        'platform.companies.list',
        'platform.companies.governance',
        'platform.approvals.list',
        'platform.approvals.manage',
        'platform.orders.list',
        'platform.payments.list',
        'platform.packages.moderate',
        'platform.stats.view',
        'localization.view',
        'localization.manage',
        'platform.settings.manage',
        'platform.users.list',
        'platform.inventory.view',
        'platform.inventory.manage',
        'platform.finance.view',
        'company.users.manage',
        'platform.admin',
        'platform.manage',
        'super_admin',
        'reviews.create',
        'reviews.view',
        'reviews.moderate',
        'imports.upload',
    ];

    public function run(): void
    {
        $roles = [
            'super_admin' => Role::query()->firstOrCreate(['name' => 'super_admin']),
            'platform_admin' => Role::query()->firstOrCreate(['name' => 'platform_admin']),
            'company_admin' => Role::query()->firstOrCreate(['name' => 'company_admin']),
            'operator_admin' => Role::query()->firstOrCreate(['name' => 'operator_admin']),
            'agent' => Role::query()->firstOrCreate(['name' => 'agent']),
        ];

        $permissionModels = [];
        foreach (self::PERMISSION_NAMES as $name) {
            $permissionModels[$name] = Permission::query()->firstOrCreate(['name' => $name]);
        }

        $allIds = array_map(fn (Permission $p) => $p->id, $permissionModels);
        $roles['super_admin']->permissions()->sync($allIds);
        $roles['company_admin']->permissions()->sync($allIds);
        $roles['operator_admin']->permissions()->sync($allIds);

        $platformPermissionIds = array_map(
            fn (string $n) => $permissionModels[$n]->id,
            array_values(array_filter(
                array_keys($permissionModels),
                fn (string $n) => str_starts_with($n, 'platform.') || in_array($n, ['localization.view', 'localization.manage', 'reviews.moderate'], true)
            ))
        );
        $roles['platform_admin']->permissions()->sync($platformPermissionIds);

        $viewOnly = array_filter(
            array_keys($permissionModels),
            fn (string $n) => str_ends_with($n, '.view')
        );
        $roles['agent']->permissions()->sync(
            array_map(fn (string $n) => $permissionModels[$n]->id, $viewOnly)
        );

        $company = Company::query()->firstOrCreate(
            ['name' => 'ZULU Test Agency'],
            ['type' => 'agency', 'status' => 'active']
        );

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@zulu.local'],
            [
                'name' => 'ZULU Super Admin',
                'password' => 'password',
                'status' => User::STATUS_ACTIVE,
            ]
        );

        UserCompany::query()->updateOrCreate(
            [
                'user_id' => $admin->id,
                'company_id' => $company->id,
            ],
            ['role_id' => $roles['super_admin']->id]
        );

        $primarySuperAdmin = User::query()->updateOrCreate(
            ['email' => self::PRIMARY_SUPER_ADMIN_EMAIL],
            [
                'name' => 'ZULU Super Admin',
                'password' => self::PRIMARY_SUPER_ADMIN_PASSWORD,
                'status' => User::STATUS_ACTIVE,
            ]
        );

        UserCompany::query()->updateOrCreate(
            [
                'user_id' => $primarySuperAdmin->id,
                'company_id' => $company->id,
            ],
            ['role_id' => $roles['super_admin']->id]
        );

        if ($this->command) {
            $this->command->newLine();
            $this->command->info('RBAC bootstrap: users ready for API (POST /api/login) and Sanctum-protected routes.');
            $this->command->info('  '.self::PRIMARY_SUPER_ADMIN_EMAIL.' / '.self::PRIMARY_SUPER_ADMIN_PASSWORD.'  (super_admin)');
            $this->command->info('  admin@zulu.local / password  (super_admin)');
            $this->command->newLine();
        }
    }
}
