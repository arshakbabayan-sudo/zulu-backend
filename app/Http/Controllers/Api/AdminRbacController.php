<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-admin RBAC oversight (Sprint 66, PART 28).
 *
 * Read-only inventory of roles + permissions + the role↔permission
 * matrix. Useful for security audits and verifying scope before any
 * fine-grained permission refactor.
 */
class AdminRbacController extends Controller
{
    /**
     * Menu-mirror permission tree (2026-06-01, Arshak's model).
     *
     * The super-admin Permissions page renders the admin menu as a tree:
     * each top-level SECTION expands (dropdown) into sub-items, and each
     * sub-item exposes the action permissions that gate it. Editing a role =
     * checking/unchecking these boxes. Operators/agents see the SAME tree but
     * capped at their own granted permissions (the "ceiling").
     *
     * Shape: section => [ label, items => [ key => [label, permissions[]] ] ].
     * Only permissions that actually exist in the seeder are referenced.
     */
    /**
     * Menu-mirror permission tree (RBAC #2 redo, 2026-06-06).
     *
     * The SECTIONS here ARE the left-menu items (one per sidebar group, in menu
     * order). Each section's first item, "Show & access", holds the single
     * `<section>.view` gate — granting it shows the menu item AND allows page +
     * API access; revoking it removes access by EVERY route (menu hidden, direct
     * URL forbidden, API 403). The remaining items are the section's action
     * permissions. There is NO separate "Left menu" list — this is the one list.
     */
    private const PERMISSION_TREE = [
        'dashboard' => [
            'label' => 'Dashboard',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['dashboard.view']],
                'stats' => ['label' => 'Platform stats', 'permissions' => ['platform.stats.view']],
            ],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['inventory.view']],
                'hotels' => ['label' => 'Hotels', 'permissions' => ['hotels.view', 'hotels.create', 'hotels.update', 'hotels.delete']],
                'flights' => ['label' => 'Flights', 'permissions' => ['flights.view', 'flights.create', 'flights.update', 'flights.delete']],
                'cars' => ['label' => 'Cars', 'permissions' => ['cars.view', 'cars.create', 'cars.update', 'cars.delete']],
                'transfers' => ['label' => 'Transfers', 'permissions' => ['transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete']],
                'excursions' => ['label' => 'Excursions', 'permissions' => ['excursions.view', 'excursions.create', 'excursions.update', 'excursions.delete']],
                'visas' => ['label' => 'Visas', 'permissions' => ['visas.view', 'visas.create', 'visas.update', 'visas.delete']],
                'packages' => ['label' => 'Packages', 'permissions' => ['packages.view', 'packages.create', 'packages.edit', 'packages.delete', 'packages.manage_components']],
                'offers' => ['label' => 'Offers', 'permissions' => ['offers.view', 'offers.create', 'offers.publish', 'offers.archive']],
            ],
        ],
        'bookings' => [
            'label' => 'Bookings',
            'items' => [
                'access' => ['label' => 'Show & view', 'permissions' => ['bookings.view']],
                'manage' => ['label' => 'Manage bookings', 'permissions' => ['bookings.create', 'bookings.confirm', 'bookings.cancel']],
                'package_orders' => ['label' => 'Package orders', 'permissions' => ['package_orders.view', 'package_orders.manage']],
            ],
        ],
        'crm' => [
            'label' => 'CRM',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['crm.view']],
                // #4 (2026-06-06) — HR + File manager folded INTO CRM. Team &
                // employees (was the HR section) + Files (was its own section) now
                // live here; Contracts (instances) are also under CRM, gated by
                // crm.view (no separate contract permission).
                'team' => ['label' => 'Team & employees', 'permissions' => ['company.users.manage']],
                'files' => ['label' => 'Files', 'permissions' => ['files.view']],
            ],
        ],
        'chat' => [
            'label' => 'Chat',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['chat.view']],
            ],
        ],
        'finance' => [
            'label' => 'Finance',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['finance.view']],
                'invoices' => ['label' => 'Invoices', 'permissions' => ['invoices.view', 'invoices.create', 'invoices.issue', 'invoices.pay', 'invoices.cancel']],
                'payments' => ['label' => 'Payments', 'permissions' => ['payments.view', 'payments.create', 'payments.pay', 'payments.capture', 'payments.fail', 'payments.refund']],
                'commissions' => ['label' => 'Commissions', 'permissions' => ['commissions.view', 'commissions.create', 'commissions.update', 'commissions.manage', 'commission_records.view']],
                'entitlements' => ['label' => 'Entitlements', 'permissions' => ['finance.entitlements.view', 'finance.entitlements.manage']],
                'settlements' => ['label' => 'Settlements', 'permissions' => ['finance.settlements.view', 'finance.settlements.manage']],
                'platform_finance' => ['label' => 'Platform finance', 'permissions' => ['platform.finance.view']],
            ],
        ],
        'my_company' => [
            'label' => 'My company',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['my_company.view']],
                'profile' => ['label' => 'Company profile', 'permissions' => ['companies.view_dashboard', 'companies.edit_profile']],
                'seller' => ['label' => 'Seller settings', 'permissions' => ['seller_permissions.view']],
            ],
        ],
        // #4 (2026-06-06) — 'hr' section removed; folded into CRM (Team & employees).
        'management' => [
            'label' => 'Management',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['management.view']],
                'companies' => ['label' => 'Companies & access', 'permissions' => ['companies.view', 'companies.manage_seller_permissions', 'platform.companies.list', 'platform.companies.governance']],
                'approvals' => ['label' => 'Approvals', 'permissions' => ['platform.approvals.list', 'platform.approvals.manage']],
                'users' => ['label' => 'Users', 'permissions' => ['platform.users.list']],
                'reviews' => ['label' => 'Reviews', 'permissions' => ['reviews.view', 'reviews.create', 'reviews.moderate']],
                'oversight' => ['label' => 'Platform oversight', 'permissions' => ['platform.orders.list', 'platform.payments.list', 'platform.packages.moderate', 'platform.inventory.view', 'platform.inventory.manage']],
            ],
        ],
        // #4 (2026-06-06) — 'files' section removed; folded into CRM (Files item).
        'inbox' => [
            'label' => 'Inbox',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['inbox.view']],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['settings.view']],
                'localization' => ['label' => 'Localization', 'permissions' => ['localization.view', 'localization.manage']],
                'platform_settings' => ['label' => 'Platform settings', 'permissions' => ['platform.settings.manage']],
                'imports' => ['label' => 'Imports', 'permissions' => ['imports.upload']],
            ],
        ],
        'profile' => [
            'label' => 'My profile',
            'items' => [
                'access' => ['label' => 'Show & access', 'permissions' => ['profile.view']],
                'account' => ['label' => 'Account', 'permissions' => ['account.update_profile', 'saved_items.manage']],
            ],
        ],
    ];

    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /** GET /api/platform-admin/rbac/roles */
    public function roles(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('memberships')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
                'description' => $r->description,
                'scope' => $r->scope,
                'memberships_count' => $r->memberships_count ?? 0,
                'permissions' => $r->permissions->map(fn (Permission $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->values(),
            ]);

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /** POST /api/platform-admin/rbac/roles */
    public function storeRole(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'name' => 'required|string|max:64|regex:/^[a-z][a-z0-9_]*$/|unique:roles,name',
            'description' => 'nullable|string|max:255',
            'scope' => 'required|in:platform,company',
            'permission_ids' => 'sometimes|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope' => $data['scope'],
        ]);

        if (! empty($data['permission_ids'])) {
            $role->permissions()->sync($data['permission_ids']);
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeRole($role->fresh(['permissions'])),
        ], 201);
    }

    /** PATCH /api/platform-admin/rbac/roles/{role} */
    public function updateRole(Request $request, Role $role): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'display_name' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string|max:255',
            'scope' => 'sometimes|in:platform,company',
        ]);

        $role->fill($data)->save();

        return response()->json([
            'success' => true,
            'data' => $this->serializeRole($role->fresh(['permissions'])),
        ]);
    }

    /** DELETE /api/platform-admin/rbac/roles/{role} */
    public function destroyRole(Request $request, Role $role): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Prevent deleting roles that still have user memberships — would
        // orphan users with no role assignment. Caller must re-assign first.
        $memberCount = $role->memberships()->count();
        if ($memberCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete role '{$role->name}' — it has {$memberCount} assigned member(s). Reassign them first.",
            ], 422);
        }

        $role->permissions()->detach();
        $role->delete();

        return response()->json(['success' => true]);
    }

    /** PUT /api/platform-admin/rbac/roles/{role}/permissions
     *
     * Sync the permission set for a role. The request body must include
     * a `permission_ids` array of permission IDs — anything not in the
     * array is revoked.
     */
    public function syncRolePermissions(Request $request, Role $role): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        // P4 fix — the tree only MANAGES the permissions it actually displays.
        // Any permission the role holds that is NOT in the tree (e.g. the
        // platform.admin / platform.manage / super_admin escalators that never
        // appear in the menu tree) is preserved, so saving a role from the tree
        // can never silently strip it. Only tree-managed perms are synced from
        // the submitted set.
        $treeManaged = $this->treePermissionIds();
        $submittedTree = array_values(array_intersect(array_map('intval', $data['permission_ids']), $treeManaged));
        $current = $role->permissions()->pluck('permissions.id')->all();
        $preservedNonTree = array_values(array_diff($current, $treeManaged));

        $role->permissions()->sync(array_values(array_unique(array_merge($submittedTree, $preservedNonTree))));

        return response()->json([
            'success' => true,
            'data' => $this->serializeRole($role->fresh(['permissions'])),
        ]);
    }

    /** Permission ids for every permission named anywhere in PERMISSION_TREE. */
    private function treePermissionIds(): array
    {
        $names = [];
        foreach (self::PERMISSION_TREE as $section) {
            foreach ($section['items'] as $item) {
                foreach ($item['permissions'] as $permName) {
                    $names[] = $permName;
                }
            }
        }

        return Permission::query()->whereIn('name', array_values(array_unique($names)))->pluck('id')->all();
    }

    private function serializeRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'scope' => $role->scope,
            'memberships_count' => $role->memberships()->count(),
            'permissions' => $role->permissions->map(fn (Permission $p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->values(),
        ];
    }

    /**
     * GET /api/platform-admin/rbac/tree?role_id=N
     *
     * The menu-mirror permission tree. Returns the admin-menu-shaped sections
     * → items → permissions, each permission annotated with its DB id and
     * (when role_id is given) whether that role currently grants it — so the UI
     * renders the sidebar as a checkbox tree with the right boxes pre-checked.
     * Permission names that don't exist in the DB are silently skipped.
     */
    public function tree(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $idByName = Permission::query()->pluck('id', 'name'); // name => id

        $grantedIds = [];
        $roleId = $request->query('role_id');
        if ($roleId !== null && $roleId !== '') {
            $role = Role::query()->with('permissions:id')->find((int) $roleId);
            if ($role !== null) {
                $grantedIds = $role->permissions->pluck('id')->all();
            }
        }

        $sections = [];
        foreach (self::PERMISSION_TREE as $sectionKey => $section) {
            $items = [];
            foreach ($section['items'] as $itemKey => $item) {
                $perms = [];
                foreach ($item['permissions'] as $permName) {
                    $pid = $idByName[$permName] ?? null;
                    if ($pid === null) {
                        continue; // permission not seeded — skip
                    }
                    $perms[] = [
                        'id' => $pid,
                        'name' => $permName,
                        'action' => substr($permName, strrpos($permName, '.') + 1),
                        'granted' => in_array($pid, $grantedIds, true),
                    ];
                }
                if ($perms !== []) {
                    $items[] = [
                        'key' => $itemKey,
                        'label' => $item['label'],
                        'permissions' => $perms,
                    ];
                }
            }
            if ($items !== []) {
                $sections[] = [
                    'key' => $sectionKey,
                    'label' => $section['label'],
                    'items' => $items,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'role_id' => $roleId !== null && $roleId !== '' ? (int) $roleId : null,
                'sections' => $sections,
            ],
        ]);
    }

    /** GET /api/platform-admin/rbac/permissions */
    public function permissions(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $permissions = Permission::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    /**
     * GET /api/platform-admin/rbac/matrix
     *
     * Returns a 2D matrix of role × permission for at-a-glance audit.
     */
    public function matrix(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $permissions = Permission::query()->orderBy('name')->get(['id', 'name']);
        $roles = Role::query()
            ->with('permissions:id')
            ->orderBy('name')
            ->get(['id', 'name']);

        $matrix = $roles->map(function (Role $role) use ($permissions) {
            $rolePermIds = $role->permissions->pluck('id')->all();

            return [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permissions' => $permissions->map(fn (Permission $p) => [
                    'permission_id' => $p->id,
                    'permission_name' => $p->name,
                    'granted' => in_array($p->id, $rolePermIds, true),
                ])->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => $permissions,
                'roles' => $matrix,
            ],
        ]);
    }

    /** GET /api/platform-admin/rbac/stats */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Phase Զ.14 — pre-existing 500 here (hidden by old page's `{stats &&`
        // guard). Wrap every count in a try/catch so the endpoint never
        // returns 500 — worst case, individual counts return 0 and the
        // frontend gracefully degrades.
        $safeCount = function (\Closure $query): int {
            try {
                return (int) $query();
            } catch (\Throwable $e) {
                \Log::warning('rbac/stats count failed', ['error' => $e->getMessage()]);
                return 0;
            }
        };

        return response()->json([
            'success' => true,
            'data' => [
                'total_roles' => $safeCount(fn () => Role::query()->count()),
                'total_permissions' => $safeCount(fn () => Permission::query()->count()),
                'total_memberships' => $safeCount(fn () => \DB::table('user_company')->count()),
                'super_admins' => $safeCount(function () {
                    $rows = \DB::table('user_company')
                        ->join('roles', 'roles.id', '=', 'user_company.role_id')
                        ->where('roles.name', 'super_admin')
                        ->where('roles.scope', 'platform')
                        ->select('user_company.user_id')
                        ->distinct()
                        ->get();
                    return $rows->count();
                }),
            ],
        ]);
    }
}
