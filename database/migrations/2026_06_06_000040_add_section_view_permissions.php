<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC #2 (redo) — one "view/access" permission per LEFT-MENU section.
 *
 * Replaces the earlier mistake (a separate `menu.*` permission set + a duplicate
 * "Left menu (visibility)" tree section). Arshak's model: the permission tree's
 * SECTIONS ARE the left-menu items. Each section has a single `<section>.view`
 * gate that controls everything for that section — sidebar visibility, page
 * access (direct URL), and server API access. No checkmark ⇒ no access by ANY
 * means. Action permissions (create/edit/delete) live under the same section.
 *
 * This migration only ADDS the gate permissions and grants each role the gates
 * matching its CURRENT menu, so the switch-over is a no-op until the frontend +
 * server gates flip to them. The old `menu.*` set is dropped in a later
 * migration once nothing references it (avoids a broken-menu window mid-deploy).
 *
 * `bookings.view` already exists and is reused as the Bookings gate.
 * Idempotent.
 */
return new class extends Migration
{
    /** menu group key => its single view/access gate permission. */
    private const SECTION_VIEW = [
        'dashboard'  => 'dashboard.view',
        'inventory'  => 'inventory.view',
        'bookings'   => 'bookings.view',   // already exists — reused
        'crm'        => 'crm.view',
        'chat'       => 'chat.view',
        'finance'    => 'finance.view',
        'my_company' => 'my_company.view',
        'hr'         => 'hr.view',
        'management' => 'management.view',
        'files'      => 'files.view',
        'inbox'      => 'inbox.view',
        'settings'   => 'settings.view',
        'profile'    => 'profile.view',
    ];

    /** Which section gates each role gets by default — mirrors today's menu. */
    private const ROLE_DEFAULTS = [
        'super_admin'    => 'ALL',
        'platform_admin' => 'ALL',
        'company_admin'  => ['dashboard', 'inventory', 'bookings', 'crm', 'chat', 'finance', 'my_company', 'hr', 'files', 'inbox', 'settings', 'profile'],
        'agent'          => ['dashboard', 'bookings', 'crm', 'chat', 'finance', 'my_company', 'hr', 'files', 'inbox', 'settings', 'profile'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('permissions', 'id'), GREATEST((SELECT MAX(id) FROM permissions), 1))");
        }

        // Create the gate permissions (bookings.view already exists → firstOrCreate is a no-op).
        $permByGroup = [];
        foreach (self::SECTION_VIEW as $group => $name) {
            $permByGroup[$group] = Permission::query()->firstOrCreate(['name' => $name]);
        }

        // Grant defaults per role — additive.
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
        // Drop only the NEW gates (never bookings.view, which predates this).
        $names = array_values(array_filter(
            self::SECTION_VIEW,
            fn (string $n) => $n !== 'bookings.view'
        ));
        $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();
        if ($ids !== []) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            Permission::query()->whereIn('id', $ids)->delete();
        }
    }
};
