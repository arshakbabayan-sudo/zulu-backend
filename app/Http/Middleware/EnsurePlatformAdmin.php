<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces platform-admin authorisation at the route-group level.
 *
 * Previously each platform-admin controller method called a private
 * `denyUnlessPlatformAdmin($request)` helper that was copy-pasted into
 * 17 controllers (I1 audit finding F-4). Any new controller method that
 * forgot to call the helper would be silently exposed to every
 * Sanctum-authenticated user — including freshly-registered customers.
 *
 * This middleware moves the check up to the route definition so the
 * gate is impossible to forget. The in-controller `denyUnlessPlatformAdmin`
 * helpers stay in place for now as defence-in-depth; they can be removed
 * in a follow-up sweep once production verifies the middleware path.
 *
 * Apply to a route group via the `platform-admin` alias registered in
 * `bootstrap/app.php`:
 *
 *   Route::middleware(['auth:sanctum', 'platform-admin'])->prefix('platform-admin')->group(function () {
 *       // every route inside is guarded
 *   });
 */
class EnsurePlatformAdmin
{
    public function __construct(private AdminAccessService $adminAccessService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}
