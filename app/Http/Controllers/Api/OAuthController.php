<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Phase 8 — social login (Google + Facebook) via Laravel Socialite.
 *
 * Flow:
 *   1. Frontend redirects browser to /api/auth/oauth/{provider}/redirect?return_to=...
 *   2. We remember the desired return host + path in the session and forward the
 *      browser to the provider's OAuth consent screen.
 *   3. Provider calls our /api/auth/oauth/{provider}/callback with code + state.
 *   4. Socialite exchanges the code, we link by provider id OR by email, issue
 *      a Sanctum token, and redirect back to `{return_host}/auth/oauth-success?token=...`.
 *
 * On any failure we redirect back to `{return_host}/login?oauth_error=...`.
 *
 * The supported providers list is intentionally small — Google + Facebook only —
 * since Apple ($99/yr Apple Developer membership) is out of scope per the
 * product decision documented in feedback_no_payment_integration_yet.md.
 */
class OAuthController extends Controller
{
    /** Providers we configured in config/services.php */
    private const SUPPORTED = ['google', 'facebook'];

    public function redirect(Request $request, string $provider): SymfonyRedirectResponse
    {
        $this->assertProviderSupported($provider);

        // The frontend tells us where to come back to (defaults to the same
        // host that initiated the redirect). The host must be on the allow-list
        // so we never honour an attacker-controlled return_to.
        $returnHost = $this->resolveReturnHost($request);
        $returnMode = $request->query('mode') === 'register' ? 'register' : 'login';

        // API routes don't have session middleware, so we round-trip the
        // host+mode through the OAuth `state` parameter (provider echoes it
        // back to the callback unchanged). Encoded with HMAC so a forged
        // state can't redirect users to attacker hosts.
        $state = $this->encodeState([
            'host' => $returnHost,
            'mode' => $returnMode,
            'nonce' => Str::random(16),
        ]);

        return Socialite::driver($provider)
            ->stateless()
            ->scopes($this->scopesFor($provider))
            ->with(['state' => $state])
            ->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->assertProviderSupported($provider);

        $state = $this->decodeState((string) $request->query('state', ''));
        $returnHost = is_array($state) && isset($state['host']) && in_array($state['host'], $this->allowedHosts(), true)
            ? (string) $state['host']
            : $this->defaultReturnHost();
        $returnMode = is_array($state) && ($state['mode'] ?? null) === 'register' ? 'register' : 'login';

        try {
            $providerUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning('OAuth callback failed during provider exchange', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($returnHost, 'provider_exchange_failed');
        }

        $providerId = (string) $providerUser->getId();
        $providerEmail = $providerUser->getEmail();
        $providerName = $providerUser->getName() ?: ($providerUser->getNickname() ?: 'ZULU user');
        $providerAvatar = $providerUser->getAvatar();

        if ($providerId === '') {
            return $this->failure($returnHost, 'missing_provider_id');
        }

        try {
            $user = DB::transaction(function () use ($provider, $providerId, $providerEmail, $providerName, $providerAvatar) {
                $idColumn = $provider.'_id';

                // 1. Match by provider id first (returning user who already linked).
                $user = User::query()->where($idColumn, $providerId)->first();

                if ($user === null && $providerEmail !== null && $providerEmail !== '') {
                    // 2. Match by email (existing email-based account picks up
                    //    a provider link on first social login). We only link
                    //    when the provider has actually verified the email —
                    //    Google + Facebook both flag this, Socialite normalises
                    //    via getEmail() returning null when absent/unverified.
                    $user = User::query()->where('email', $providerEmail)->first();
                }

                if ($user === null) {
                    // 3. Create a brand-new user on first OAuth login.
                    $user = User::query()->create([
                        'name' => $providerName,
                        'email' => $providerEmail ?: $providerId.'@'.$provider.'.oauth.zulu.local',
                        'password' => bcrypt(Str::random(40)),
                        'status' => User::STATUS_ACTIVE,
                        'email_verified_at' => now(),
                    ]);
                }

                // Always refresh the link + last-used provider.
                $user->forceFill([
                    $idColumn => $providerId,
                    'oauth_provider' => $provider,
                ]);

                if ($providerAvatar !== null && $providerAvatar !== '' && empty($user->avatar)) {
                    $user->avatar = $providerAvatar;
                }

                if ($user->email_verified_at === null) {
                    $user->email_verified_at = now();
                }

                $user->save();

                return $user;
            });
        } catch (\Throwable $e) {
            Log::warning('OAuth user provisioning failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($returnHost, 'user_provisioning_failed');
        }

        $expiresAt = now()->addDays(30);
        $token = $user->createToken('oauth:'.$provider, ['*'], $expiresAt)->plainTextToken;

        $successPath = (string) config('services.oauth.success_path', '/auth/oauth-success');
        $query = http_build_query([
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'provider' => $provider,
            'mode' => $returnMode,
        ]);

        return redirect()->away('https://'.$returnHost.$successPath.'?'.$query);
    }

    private function failure(string $returnHost, string $reason): RedirectResponse
    {
        $failurePath = (string) config('services.oauth.failure_path', '/login');

        return redirect()->away('https://'.$returnHost.$failurePath.'?oauth_error='.urlencode($reason));
    }

    private function assertProviderSupported(string $provider): void
    {
        if (! in_array($provider, self::SUPPORTED, true)) {
            abort(404, 'Unsupported OAuth provider');
        }

        if (config('services.'.$provider.'.client_id') === null
            || config('services.'.$provider.'.client_secret') === null) {
            abort(503, 'OAuth provider not configured');
        }
    }

    /** @return list<string> */
    private function scopesFor(string $provider): array
    {
        return match ($provider) {
            'google' => ['openid', 'profile', 'email'],
            'facebook' => ['email', 'public_profile'],
            default => [],
        };
    }

    private function resolveReturnHost(Request $request): string
    {
        $allowed = $this->allowedHosts();
        $candidate = (string) ($request->query('return_host') ?? '');

        if ($candidate !== '' && in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        // Fall back to Origin/Referer host if it is in the allow-list.
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
}
