<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Per-tenant Google Drive OAuth (Phase Է, 2026-05-25).
 *
 *   GET  /api/auth/google-drive/redirect?company_id=N  (admin only)
 *   GET  /api/auth/google-drive/callback?code=...&state=...
 *   GET  /api/companies/{company}/google-drive/status   (admin only)
 *   DELETE /api/companies/{company}/google-drive       (admin only)
 *
 * This is DELIBERATELY a separate controller from OAuthController
 * (which handles "sign in with Google" social login). Mixing them
 * would force every signed-in user through the Drive consent screen.
 *
 * The flow:
 *   1. Admin clicks "Connect Drive" → frontend hits /redirect with
 *      the company_id they're acting on. We bind that company_id +
 *      a random nonce into an HMAC-signed `state` parameter and
 *      forward the browser to Google's consent screen with
 *      access_type=offline + prompt=consent so a refresh_token is
 *      always returned.
 *   2. Google bounces back to /callback with code + state. We
 *      verify the state, exchange the code for tokens, persist them
 *      onto the company row, create the ZULU_Spin_Files root folder,
 *      and redirect the browser back to the admin integrations page.
 */
class GoogleDriveAuthController extends Controller
{
    public function __construct(private readonly GoogleDriveService $drive) {}

    public function redirect(Request $request): SymfonyRedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
            'return_host' => ['nullable', 'string', 'max:255'],
        ]);

        $companyId = (int) $validated['company_id'];
        $company = Company::query()->find($companyId);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        $user = $request->user();
        if ($user === null || ! $this->canAdminCompany($user, $companyId)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a company admin can connect Drive',
            ], 403);
        }

        try {
            $client = $this->drive->makeBaseClient();
        } catch (GoogleDriveException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Google Drive is not configured on the server',
                'detail' => $e->getMessage(),
            ], 503);
        }

        $returnHost = $this->resolveReturnHost($request, $validated['return_host'] ?? null);

        $state = $this->encodeState([
            'company_id' => $companyId,
            'host' => $returnHost,
            'user_id' => (int) $user->id,
            'nonce' => Str::random(16),
        ]);

        $client->setState($state);

        $authUrl = $client->createAuthUrl();

        // The admin SPA authenticates with a Bearer token, which a full-page
        // browser navigation cannot carry (so navigating straight here would
        // 401). The frontend therefore calls this endpoint via fetch (with the
        // Authorization header) using ?json=1, gets the Google auth URL back as
        // JSON, then navigates the browser to it. The Sanctum token never
        // travels in a query string / referrer / log this way.
        if ($request->boolean('json')) {
            return response()->json([
                'success' => true,
                'data' => ['auth_url' => $authUrl],
            ]);
        }

        return redirect()->away($authUrl);
    }

    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $code = (string) $request->query('code', '');
        $stateRaw = (string) $request->query('state', '');
        $errorParam = (string) $request->query('error', '');

        $state = $this->decodeState($stateRaw);
        $returnHost = is_array($state) && isset($state['host']) && in_array($state['host'], $this->allowedHosts(), true)
            ? (string) $state['host']
            : $this->defaultReturnHost();

        if ($errorParam !== '') {
            return $this->failure($returnHost, 'user_denied:'.$errorParam);
        }

        if ($code === '' || ! is_array($state) || ! isset($state['company_id'])) {
            return $this->failure($returnHost, 'invalid_state');
        }

        $companyId = (int) $state['company_id'];
        $company = Company::query()->find($companyId);
        if ($company === null) {
            return $this->failure($returnHost, 'company_not_found');
        }

        try {
            $client = $this->drive->makeBaseClient();
            $tokens = $client->fetchAccessTokenWithAuthCode($code);
        } catch (\Throwable $e) {
            Log::warning('Google Drive token exchange failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($returnHost, 'token_exchange_failed');
        }

        if (isset($tokens['error'])) {
            return $this->failure($returnHost, 'token_error:'.((string) $tokens['error']));
        }

        // Decode the id_token (if Google returned one) to surface the
        // connected email back to the admin UI. This is best-effort —
        // failure to decode does not abort the connection.
        $connectedEmail = $this->extractEmailFromIdToken((string) ($tokens['id_token'] ?? ''));

        try {
            $this->drive->persistTokens($company, $tokens, $connectedEmail);
            $this->drive->ensureRootFolder($company);
        } catch (GoogleDriveException $e) {
            Log::error('Google Drive folder bootstrap failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($returnHost, 'folder_bootstrap_failed');
        }

        $successPath = (string) config('services.google_drive.success_path', '/admin/settings/integrations');
        $query = http_build_query([
            'google_drive' => 'connected',
            'company_id' => $companyId,
        ]);

        return redirect()->away('https://'.$returnHost.$successPath.'?'.$query);
    }

    /** GET /api/companies/{company}/google-drive/status */
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

        return response()->json([
            'success' => true,
            'data' => [
                'connected' => $company->hasGoogleDriveConnected(),
                'connected_email' => $company->google_drive_connected_email,
                'connected_at' => optional($company->google_drive_connected_at)->toIso8601String(),
                'folder_id' => $company->google_drive_folder_id,
            ],
        ]);
    }

    /** DELETE /api/companies/{company}/google-drive */
    public function disconnect(Request $request, int $companyId): JsonResponse
    {
        $company = Company::query()->find($companyId);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        $user = $request->user();
        if ($user === null || ! $this->canAdminCompany($user, $companyId)) {
            return response()->json(['success' => false, 'message' => 'Only a company admin can disconnect Drive'], 403);
        }

        $this->drive->disconnect($company);

        return response()->json(['success' => true, 'data' => ['connected' => false]]);
    }

    /* ────────────────────────────────────────────────────────────── */

    /**
     * Admin permission gate. Currently: super-admin OR any member of the
     * company. The company membership grants admin rights only because
     * Drive is per-tenant — a finer-grained "company.settings.manage"
     * permission can be wired through hasCompanyPermission() later.
     */
    private function canAdminCompany($user, int $companyId): bool
    {
        if ($user->is_super_admin) {
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

    private function resolveReturnHost(Request $request, ?string $hint): string
    {
        $allowed = $this->allowedHosts();

        if ($hint !== null && $hint !== '' && in_array($hint, $allowed, true)) {
            return $hint;
        }

        foreach (['origin', 'referer'] as $header) {
            $value = $request->headers->get($header);
            if (! is_string($value) || $value === '') {
                continue;
            }
            $host = parse_url($value, PHP_URL_HOST);
            if (is_string($host) && in_array($host, $allowed, true)) {
                return $host;
            }
        }

        return $this->defaultReturnHost();
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $raw = (string) config('services.oauth.allowed_frontend_hosts', '');
        $hosts = array_filter(array_map('trim', explode(',', $raw)));

        return array_values($hosts);
    }

    private function defaultReturnHost(): string
    {
        $allowed = $this->allowedHosts();

        return $allowed[0] ?? 'zulu.am';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeState(array $payload): string
    {
        $body = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
        $sig = hash_hmac('sha256', $body, (string) config('app.key'));

        return $body.'.'.$sig;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeState(string $raw): ?array
    {
        if ($raw === '' || ! str_contains($raw, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $raw, 2);
        $expected = hash_hmac('sha256', $body, (string) config('app.key'));
        if (! hash_equals($expected, $sig)) {
            return null;
        }
        $decoded = json_decode((string) base64_decode($body, true), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function extractEmailFromIdToken(string $idToken): ?string
    {
        if ($idToken === '' || substr_count($idToken, '.') !== 2) {
            return null;
        }
        [, $payload] = explode('.', $idToken, 3);
        $padded = strtr($payload, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return null;
        }
        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['email']) ? (string) $json['email'] : null;
    }

    private function failure(string $returnHost, string $reason): RedirectResponse
    {
        $successPath = (string) config('services.google_drive.success_path', '/admin/settings/integrations');

        return redirect()->away('https://'.$returnHost.$successPath.'?google_drive=error&reason='.urlencode($reason));
    }
}
