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
        // guard) caused by User::superAdmins() Eloquent scope failing on
        // production. Replaced with a direct DB-level join — same result,
        // bypasses any model/relationship loading paths that could fail.
        $superAdmins = \DB::table('user_companies')
            ->join('roles', 'roles.id', '=', 'user_companies.role_id')
            ->where('roles.name', 'super_admin')
            ->where('roles.scope', 'platform')
            ->distinct('user_companies.user_id')
            ->count('user_companies.user_id');

        return response()->json([
            'success' => true,
            'data' => [
                'total_roles' => Role::query()->count(),
                'total_permissions' => Permission::query()->count(),
                'total_memberships' => \DB::table('user_companies')->count(),
                'super_admins' => $superAdmins,
            ],
        ]);
    }
}
