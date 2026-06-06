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
    private const PERMISSION_TREE = [
        // Left menu (RBAC #2 Part Ա, 2026-06-06) — one checkbox per sidebar group.
        // Checking it shows that group in the operator/agent left menu; the
        // action permissions in the sections below gate what they can DO inside.
        // Listed FIRST so it's the prominent control Arshak reaches for.
        'menu' => [
            'label' => 'Left menu (visibility)',
            'items' => [
                'dashboard' => ['label' => 'Dashboard', 'permissions' => ['menu.dashboard.view']],
                'inventory' => ['label' => 'Inventory', 'permissions' => ['menu.inventory.view']],
                'bookings' => ['label' => 'Bookings', 'permissions' => ['menu.bookings.view']],
                'crm' => ['label' => 'CRM', 'permissions' => ['menu.crm.view']],
                'chat' => ['label' => 'Chat', 'permissions' => ['menu.chat.view']],
                'finance' => ['label' => 'Finance', 'permissions' => ['menu.finance.view']],
                'my_company' => ['label' => 'My company', 'permissions' => ['menu.my_company.view']],
                'hr' => ['label' => 'HR', 'permissions' => ['menu.hr.view']],
                'management' => ['label' => 'Management', 'permissions' => ['menu.management.view']],
                'files' => ['label' => 'File manager', 'permissions' => ['menu.files.view']],
                'inbox' => ['label' => 'Inbox', 'permissions' => ['menu.inbox.view']],
                'settings' => ['label' => 'Settings', 'permissions' => ['menu.settings.view']],
                'profile' => ['label' => 'My profile', 'permissions' => ['menu.profile.view']],
            ],
        ],
        'dashboard' => [
            'label' => 'Dashboard',
            'items' => [
                'overview' => ['label' => 'Overview & stats', 'permissions' => ['platform.stats.view']],
            ],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'items' => [
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
                'bookings' => ['label' => 'Bookings', 'permissions' => ['bookings.view', 'bookings.create', 'bookings.confirm', 'bookings.cancel']],
                'package_orders' => ['label' => 'Package orders', 'permissions' => ['package_orders.view', 'package_orders.manage']],
            ],
        ],
        'finance' => [
            'label' => 'Finance',
            'items' => [
                'invoices' => ['label' => 'Invoices', 'permissions' => ['invoices.view', 'invoices.create', 'invoices.issue', 'invoices.pay', 'invoices.cancel']],
                'payments' => ['label' => 'Payments', 'permissions' => ['payments.view', 'payments.create', 'payments.pay', 'payments.capture', 'payments.fail', 'payments.refund']],
                'commissions' => ['label' => 'Commissions', 'permissions' => ['commissions.view', 'commissions.create', 'commissions.update', 'commissions.manage', 'commission_records.view']],
                'entitlements' => ['label' => 'Entitlements', 'permissions' => ['finance.entitlements.view', 'finance.entitlements.manage']],
                'settlements' => ['label' => 'Settlements', 'permissions' => ['finance.settlements.view', 'finance.settlements.manage']],
                'platform_finance' => ['label' => 'Platform finance', 'permissions' => ['platform.finance.view']],
            ],
        ],
        'management' => [
            'label' => 'Management',
            'items' => [
                'companies' => ['label' => 'Companies & access', 'permissions' => ['companies.view', 'companies.view_dashboard', 'companies.edit_profile', 'companies.manage_seller_permissions', 'platform.companies.list', 'platform.companies.governance']],
                'approvals' => ['label' => 'Approvals', 'permissions' => ['platform.approvals.list', 'platform.approvals.manage']],
                'reviews' => ['label' => 'Reviews', 'permissions' => ['reviews.view', 'reviews.create', 'reviews.moderate']],
                'oversight' => ['label' => 'Platform oversight', 'permissions' => ['platform.orders.list', 'platform.payments.list', 'platform.packages.moderate', 'platform.inventory.view', 'platform.inventory.manage']],
            ],
        ],
        'directory' => [
            'label' => 'Directory',
            'items' => [
                'users' => ['label' => 'Users', 'permissions' => ['platform.users.list']],
                'employees' => ['label' => 'Company employees', 'permissions' => ['company.users.manage']],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'items' => [
                'localization' => ['label' => 'Localization', 'permissions' => ['localization.view', 'localization.manage']],
                'platform_settings' => ['label' => 'Platform settings', 'permissions' => ['platform.settings.manage']],
                'imports' => ['label' => 'Imports', 'permissions' => ['imports.upload']],
                'seller_permissions' => ['label' => 'Seller permissions', 'permissions' => ['seller_permissions.view']],
            ],
        ],
        'account' => [
            'label' => 'My account',
            'items' => [
                'profile' => ['label' => 'Profile', 'permissions' => ['account.update_profile']],
                'saved' => ['label' => 'Saved items', 'permissions' => ['saved_items.manage']],
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

        $role->permissions()->sync($data['permission_ids']);

        return response()->json([
            'success' => true,
            'data' => $this->serializeRole($role->fresh(['permissions'])),
        ]);
    }

    private function serializeRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
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
