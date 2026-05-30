<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Models\UserTwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email-channel 2FA login flow (Arshak 2026-05-31 — 2FA hierarchy Phase A).
 *
 * Locks in:
 *   - `users.two_factor_required=true` (no TOTP secret) triggers the challenge
 *     just like a TOTP-enrolled user;
 *   - login() sends a TwoFactorCodeMail and persists the hashed code +
 *     10-min TTL on user_two_factor;
 *   - TwoFactorController::verify accepts the email code from the mail
 *     payload, mints the real session token, and consumes the stored hash;
 *   - wrong codes are rejected with 422 and don't burn the challenge;
 *   - PUT /api/account/2fa/method updates the user's chosen channel.
 */
class EmailTwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_for_email_method_user_returns_challenge_and_emails_code(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
            'two_factor_required' => true,
            'two_factor_method' => 'email',
        ]);

        $res = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requires_2fa', true)
            ->assertJsonPath('data.method', 'email')
            ->assertJsonPath('data.challenge_token', fn ($t) => is_string($t) && strlen($t) > 0)
            ->assertJsonMissingPath('data.token');

        Mail::assertSent(TwoFactorCodeMail::class, fn (TwoFactorCodeMail $mail) => $mail->user->is($user)
            && preg_match('/^\d{6}$/', $mail->code) === 1
        );

        $tf = UserTwoFactor::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($tf);
        $this->assertNotNull($tf->email_code_hash);
        $this->assertTrue($tf->email_code_expires_at->isFuture());
    }

    public function test_verify_email_code_mints_session_token_and_clears_hash(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
            'two_factor_required' => true,
            'two_factor_method' => 'email',
        ]);

        $loginRes = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertOk();
        $challenge = $loginRes->json('data.challenge_token');

        // Pull the code out of the mailable since Mail::fake doesn't actually
        // deliver — same pattern as the existing TwoFactorLoginTest for TOTP.
        $code = null;
        Mail::assertSent(TwoFactorCodeMail::class, function (TwoFactorCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->assertNotNull($code);

        $verifyRes = $this->withToken($challenge)
            ->postJson('/api/account/2fa/verify', ['code' => $code]);

        $verifyRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 0)
            ->assertJsonPath('data.user.id', $user->id);

        $tf = UserTwoFactor::query()->where('user_id', $user->id)->first();
        $this->assertNull($tf->email_code_hash, 'hash cleared after successful verify');
        $this->assertNull($tf->email_code_expires_at, 'expiry cleared after successful verify');
    }

    public function test_verify_email_code_rejects_wrong_code(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
            'two_factor_required' => true,
            'two_factor_method' => 'email',
        ]);

        $challenge = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertOk()->json('data.challenge_token');

        $this->withToken($challenge)
            ->postJson('/api/account/2fa/verify', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // The challenge isn't burned — user can still retry with the real code.
        $tf = UserTwoFactor::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($tf->email_code_hash, 'wrong attempt must not consume the code');
    }

    public function test_set_method_endpoint_updates_user_choice(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
        ]);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/account/2fa/method', ['method' => 'email'])
            ->assertOk()
            ->assertJsonPath('data.two_factor_method', 'email');
        $this->assertSame('email', $user->refresh()->two_factor_method);

        $this->withToken($token)
            ->putJson('/api/account/2fa/method', ['method' => 'totp'])
            ->assertOk()
            ->assertJsonPath('data.two_factor_method', 'totp');
        $this->assertSame('totp', $user->refresh()->two_factor_method);

        $this->withToken($token)
            ->putJson('/api/account/2fa/method', ['method' => 'sms'])
            ->assertStatus(422);
    }

    public function test_required_flag_alone_triggers_email_flow_even_without_method_set(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => 'Password123',
            'status' => User::STATUS_ACTIVE,
            'two_factor_required' => true,
            'two_factor_method' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertOk()
            ->assertJsonPath('data.requires_2fa', true)
            ->assertJsonPath('data.method', 'email');

        Mail::assertSent(TwoFactorCodeMail::class);
    }
}
