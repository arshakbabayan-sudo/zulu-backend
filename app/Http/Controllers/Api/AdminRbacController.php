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
                'memberships_count' => $r->memberships_count ?? 0,
                'permissions' => $r->permissions->map(fn (Permission $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->values(),
            ]);

        return response()->json(['success' => true, 'data' => $roles]);
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

        return response()->json([
            'success' => true,
            'data' => [
                'total_roles' => Role::query()->count(),
                'total_permissions' => Permission::query()->count(),
                'total_memberships' => \DB::table('user_companies')->count(),
                'super_admins' => User::query()->where('is_super_admin', true)->count(),
            ],
        ]);
    }
}
