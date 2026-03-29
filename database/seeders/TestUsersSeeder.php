<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Ստեղծիր Zulu Platform company (super admin-ի home company) ──
        $platformCompany = Company::query()->updateOrCreate(
            ['name' => 'ZULU SPIN Platform'],
            [
                'legal_name'         => 'ZULU SPIN Platform LLC',
                'type'               => 'platform',
                'governance_status'  => 'active',
                'country'            => 'AM',
                'city'               => 'Yerevan',
                'address'            => 'Platform HQ',
                'phone'              => '+37400000000',
                'tax_id'             => 'PLATFORM001',
                'status'             => 'active',
                'is_seller'          => false,
                'profile_completed'  => true,
            ]
        );

        // ── 2. Super Admin user ──────────────────────────────────
        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'admin@zuluspin.com'],
            [
                'name'     => 'Zulu Super Admin',
                'password' => Hash::make('password'),
                'status'   => 'active',
            ]
        );

        $superAdminRole = Role::query()->where('name', 'super_admin')->first();
        if ($superAdminRole) {
            UserCompany::query()->updateOrCreate(
                ['user_id' => $superAdmin->id, 'company_id' => $platformCompany->id],
                ['role_id' => $superAdminRole->id]
            );
        }

        // ── 3. Test Travel Agency company ───────────────────────
        $testCompany = Company::query()->updateOrCreate(
            ['name' => 'Test Travel Agency'],
            [
                'legal_name'        => 'Test Travel Agency LLC',
                'type'              => 'agency',
                'governance_status' => 'active',
                'country'           => 'AM',
                'city'              => 'Yerevan',
                'address'           => '1 Test Street, Yerevan',
                'phone'             => '+37491000001',
                'tax_id'            => 'TEST123456',
                'status'            => 'active',
                'is_seller'         => true,
                'profile_completed' => true,
            ]
        );

        // ── 4. Company Admin user ────────────────────────────────
        $companyAdmin = User::query()->updateOrCreate(
            ['email' => 'company@zuluspin.com'],
            [
                'name'     => 'Company Admin',
                'password' => Hash::make('password'),
                'status'   => 'active',
            ]
        );

        $companyAdminRole = Role::query()->where('name', 'company_admin')->first();
        if ($companyAdminRole) {
            UserCompany::query()->updateOrCreate(
                ['user_id' => $companyAdmin->id, 'company_id' => $testCompany->id],
                ['role_id' => $companyAdminRole->id]
            );
        }

        // ── 5. Regular B2C user ──────────────────────────────────
        User::query()->updateOrCreate(
            ['email' => 'user@zuluspin.com'],
            [
                'name'     => 'Test User',
                'password' => Hash::make('password'),
                'status'   => 'active',
            ]
        );

        $this->command->info('');
        $this->command->info('✅ Test users created:');
        $this->command->info('');
        $this->command->info('  Admin Panel login → http://127.0.0.1:8008/admin/login');
        $this->command->info('');
        $this->command->info('  admin@zuluspin.com   / password  → Super Admin (Platform)');
        $this->command->info('  company@zuluspin.com / password  → Company Admin (Test Travel Agency)');
        $this->command->info('');
        $this->command->info('  API login → POST /api/login');
        $this->command->info('  user@zuluspin.com    / password  → B2C User');
        $this->command->info('');
    }
}
