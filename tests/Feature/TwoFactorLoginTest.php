<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Security\TotpGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Locks in the login → 2FA-challenge → session-token flow (Block 2.4 fix).
 *
 * Before the fix, login() always minted a full token regardless of 2FA, and
 * the verify endpoint never issued a token. These tests guard the corrected
 * handshake so it can't silently regress.
 */
class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    /** Enable confirmed 2FA on a user and return the raw TOTP secret. */
    private function enableTwoFactor(User $user): string
    {
        $secret = app(TotpGenerator::class)->generateSecret();

        UserTwoFactor::query()->create([
            'user_id' => $user->id,
            'secret_encrypted' => Crypt::encryptString($secret),
            'recovery_codes_encrypted' => [],
            'enabled_at' => now(),
            'confirmed_at' => now(),
        ]);

        return $secret;
    }

    private function currentCode(string $secret): string
    {
        // codeAt() takes the 30-second time step, not a wall-clock timestamp.
        return app(TotpGenerator::class)->codeAt($secret, intdiv(time(), TotpGenerator::PERIOD));
    }

    public function test_login_without_2fa_returns_full_token(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 0)
            ->assertJsonMissingPath('data.requires_2fa');
    }

    public function test_login_with_2fa_returns_challenge_not_session_token(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->enableTwoFactor($user);

        $res = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requires_2fa', true)
            ->assertJsonPath('data.challenge_token', fn ($t) => is_string($t) && strlen($t) > 0)
            // Crucially: NO full session token or user leaked at this stage.
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user');
    }

    public function test_valid_code_exchanges_challenge_for_session_token(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
        ]);
        $secret = $this->enableTwoFactor($user);

        $challenge = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->json('data.challenge_token');

        $this->withHeader('Authorization', 'Bearer '.$challenge)
            ->postJson('/api/account/2fa/verify', ['code' => $this->currentCode($secret)])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 0)
            ->assertJsonPath('data.user.email', $user->email);

        // The one-time challenge token must be consumed (deleted) after use.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => '2fa_challenge',
        ]);
    }

    public function test_invalid_code_returns_422_and_keeps_challenge(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->enableTwoFactor($user);

        $challenge = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->json('data.challenge_token');

        $this->withHeader('Authorization', 'Bearer '.$challenge)
            ->postJson('/api/account/2fa/verify', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Challenge token survives a wrong attempt so the user can retry.
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => '2fa_challenge',
        ]);
    }

    public function test_full_session_token_cannot_be_exchanged_at_verify(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
        ]);
        $secret = $this->enableTwoFactor($user);

        // A normal (wildcard) session token must NOT be accepted by the
        // challenge-exchange endpoint — only a "2fa:challenge" token may.
        $fullToken = $user->createToken('api', ['*'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$fullToken)
            ->postJson('/api/account/2fa/verify', ['code' => $this->currentCode($secret)])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
