<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Payments\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0-1 step 1.1 — Stripe Connect onboarding HTTP surface.
 *
 *   POST   /api/companies/{company}/stripe-connect/onboarding-link
 *   GET    /api/companies/{company}/stripe-connect/status
 *
 * Both routes inherit auth:sanctum from the surrounding group. Per-call
 * authorization is the same gate as GoogleDriveAuthController: super-admin,
 * company.settings.manage permission, or any member of the company.
 *
 * Why two endpoints (not one):
 *   - status is cheap, called on page load to render the card state.
 *   - onboarding-link makes a Stripe API call + creates an Account on
 *     first invocation, so we don't want every page load to incur that.
 */
class StripeConnectController extends Controller
{
    public function __construct(private readonly StripeConnectService $connect) {}

    /**
     * GET /api/companies/{company}/stripe-connect/status
     *
     * Returns the connection state. If the company has a Stripe account,
     * we ALSO call syncAccountStatus() to refresh the booleans — this is
     * how the card updates when the user finishes Stripe-hosted onboarding
     * in another tab and comes back. Webhook (step 1.4) will eventually
     * make this sync redundant.
     */
    public function status(Request $request, int $companyId): JsonResponse
    {
        $company = Company::query()->find($companyId);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        $user = $request->user();
        if ($user === null || ! $this->canViewCompany($user, $companyId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $sync = $this->connect->syncAccountStatus($company);
        $company->refresh();

        return response()->json([
            'success' => true,
            'data' => [
                'connected' => $company->stripe_connect_id !== null,
                'stripe_account_id' => $company->stripe_connect_id,
                'charges_enabled' => (bool) $company->stripe_charges_enabled,
                'payouts_enabled' => (bool) $company->stripe_payouts_enabled,
                'details_submitted' => (bool) $company->stripe_details_submitted,
                'ready' => $company->hasStripeConnectReady(),
                'sync_error' => $sync['success'] === false ? ($sync['error'] ?? null) : null,
            ],
        ]);
    }

    /**
     * POST /api/companies/{company}/stripe-connect/onboarding-link
     *
     * Body:
     *   return_url   string (required, HTTPS, host must be in
     *                 services.oauth.allowed_frontend_hosts) — where Stripe
     *                 sends the user after onboarding completes.
     *   refresh_url  string (required, HTTPS, same host rule) — where
     *                 Stripe sends the user if the link expires mid-flow.
     *
     * Response:
     *   { success: true, data: { url, expires_at, stripe_account_id } }
     *
     * Side effect: creates the Stripe Account if missing.
     */
    public function createOnboardingLink(Request $request, int $companyId): JsonResponse
    {
        $company = Company::query()->find($companyId);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        $user = $request->user();
        if ($user === null || ! $this->canAdminCompany($user, $companyId)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a company admin can connect Stripe',
            ], 403);
        }

        $validated = $request->validate([
            'return_url' => ['required', 'string', 'url:https', 'max:2048'],
            'refresh_url' => ['required', 'string', 'url:https', 'max:2048'],
        ]);

        if (! $this->urlHostAllowed($validated['return_url'])
            || ! $this->urlHostAllowed($validated['refresh_url'])) {
            return response()->json([
                'success' => false,
                'message' => 'return_url and refresh_url must point to an allowed host.',
            ], 422);
        }

        if (! $this->connect->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe is not configured on this server.',
                'detail' => $this->connect->configurationError(),
            ], 503);
        }

        $result = $this->connect->createOnboardingLink(
            $company,
            $validated['return_url'],
            $validated['refresh_url']
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create Stripe onboarding link.',
                'detail' => $result['error'] ?? null,
            ], 502);
        }

        $company->refresh();

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $result['url'],
                'expires_at' => $result['expires_at'] ?? null,
                'stripe_account_id' => $company->stripe_connect_id,
            ],
        ]);
    }

    /* ────────────────────────────────────────────────────────────── */

    private function canAdminCompany($user, int $companyId): bool
    {
        if (! empty($user->is_super_admin)) {
            return true;
        }
        if (method_exists($user, 'hasCompanyPermission')
            && $user->hasCompanyPermission($companyId, 'company.settings.manage')) {
            return true;
        }

        return method_exists($user, 'belongsToCompany') && $user->belongsToCompany($companyId);
    }

    private function canViewCompany($user, int $companyId): bool
    {
        return $this->canAdminCompany($user, $companyId);
    }

    private function urlHostAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $raw = (string) config('services.oauth.allowed_frontend_hosts', '');
        $allowed = array_filter(array_map('trim', explode(',', $raw)));
        if (empty($allowed)) {
            return false;
        }

        return in_array($host, $allowed, true);
    }
}
