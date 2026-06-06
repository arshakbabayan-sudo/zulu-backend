<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC #2 Part Ա — left-menu visibility permissions (Arshak's model, 2026-06-06).
 *
 * The super-admin must be able to check a box per left-menu item and have that
 * control whether an operator/agent sees it in their sidebar. Until now the
 * sidebar mixed permission / role / always gating, so toggling a role's
 * permissions barely changed the menu ("ես դնում եմ թույլտվություն, չի ազդում").
 *
 * This introduces ONE dedicated `menu.<group>.view` permission per sidebar group.
 * AdminShell gates each group purely on it, and the RBAC tree surfaces them as a
 * "Left menu" section of single checkboxes. The action permissions (bookings.create,
 * invoices.issue, …) stay separate — they gate server ACTIONS, not visibility.
 *
 * This migration creates the permissions and grants the default set to every
 * existing role so NO live operator/agent loses a menu item on deploy. After this,
 * sidebar visibility is fully driven by the checkboxes.
 *
 * Idempotent; safe to re-run.
 */
return new class extends Migration
{
    /** group key => menu permission name. Mirrors ADMIN_NAV_GROUPS in the admin frontend. */
    private const MENU_PERMS = [
        'dashboard'  => 'menu.dashboard.view',
        'inventory'  => 'menu.inventory.view',
        'bookings'   => 'menu.bookings.view',
        'crm'        => 'menu.crm.view',
        'chat'       => 'menu.chat.view',
        'finance'    => 'menu.finance.view',
        'my_company' => 'menu.my_company.view',
        'hr'         => 'menu.hr.view',
        'management' => 'menu.management.view',
        'files'      => 'menu.files.view',
        'inbox'      => 'menu.inbox.view',
        'settings'   => 'menu.settings.view',
        'profile'    => 'menu.profile.view',
    ];

    /**
     * Default menu grants per role — chosen to preserve each role's CURRENT
     * sidebar so the deploy is a no-op visually; from here Arshak edits the
     * checkboxes. 'ALL' = every menu group.
     */
    private const ROLE_DEFAULTS = [
        'super_admin'    => 'ALL', // unrestricted anyway; granted for a complete row
        'platform_admin' => 'ALL', // ZULU staff: full menu by default, super narrows per person
        'company_admin'  => ['dashboard', 'inventory', 'bookings', 'crm', 'chat', 'finance', 'my_company', 'hr', 'files', 'inbox', 'settings', 'profile'],
        'operator_admin' => ['dashboard', 'inventory', 'bookings', 'crm', 'chat', 'finance', 'my_company', 'hr', 'files', 'inbox', 'settings', 'profile'],
        'agent'          => ['dashboard', 'bookings', 'crm', 'chat', 'finance', 'my_company', 'hr', 'files', 'inbox', 'settings', 'profile'], // no Inventory, no Management
    ];

    public function up(): void
    {
        // Defensive: keep the id sequence ahead of MAX(id) before inserting, so a
        // trailing Postgres sequence (after past explicit-id seed inserts) can't
        // cause a 23505 unique-violation on the firstOrCreate below.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('permissions', 'id'), GREATEST((SELECT MAX(id) FROM permissions), 1))");
        }

        // 1) Create the menu-visibility permissions.
        $permByGroup = [];
        foreach (self::MENU_PERMS as $group => $name) {
            $permByGroup[$group] = Permission::query()->firstOrCreate(['name' => $name]);
        }

        // 2) Grant defaults per role — additive (never detaches existing perms).
        foreach (self::ROLE_DEFAULTS as $roleName => $groups) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role === null) {
                continue;
            }
            $ids = $groups === 'ALL'
                ? array_map(fn (Permission $p) => $p->id, $permByGroup)
                : array_map(fn (string $g) => $permByGroup[$g]->id, $groups);
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', array_values(self::MENU_PERMS))->pluck('id')->all();
        if ($ids !== []) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            Permission::query()->whereIn('id', $ids)->delete();
        }
    }
};
