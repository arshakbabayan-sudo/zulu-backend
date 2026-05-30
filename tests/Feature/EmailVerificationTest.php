<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * B2C email verification at signup (Arshak 2026-05-31).
 *
 * Locks in:
 *   - register endpoint emails a 6-digit verification code instead of
 *     Laravel's link-based default;
 *   - /account/verify-email accepts the code, marks email_verified_at, and
 *     clears the stored hash so a replay returns 422;
 *   - resend endpoint generates a fresh code and ignores already-verified
 *     users (200 no-op, no mail);
 *   - wrong code returns 422 and does NOT consume the code.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_sends_6_digit_code_and_stores_hash(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'terms_accepted' => true,
            'age_confirmed' => true,
        ])->assertStatus(201);

        $user = User::query()->where('email', 'alice@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at, 'email is NOT verified yet at signup');
        $this->assertNotNull($user->email_verification_code_hash);
        $this->assertTrue($user->email_verification_code_expires_at->isFuture());

        Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $m) use ($user) {
            return $m->user->is($user)
                && preg_match('/^\d{6}$/', $m->code) === 1;
        });
    }

    public function test_verify_email_marks_account_verified_and_clears_code(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'terms_accepted' => true,
            'age_confirmed' => true,
        ])->assertStatus(201);

        $code = null;
        Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->assertNotNull($code);

        $user = User::query()->where('email', 'bob@example.com')->firstOrFail();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/account/verify-email', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_code_hash);
        $this->assertNull($user->email_verification_code_expires_at);
    }

    public function test_verify_email_rejects_wrong_code_without_burning_it(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'terms_accepted' => true,
            'age_confirmed' => true,
        ])->assertStatus(201);

        $user = User::query()->where('email', 'carol@example.com')->firstOrFail();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/account/verify-email', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->email_verification_code_hash, 'wrong code must not consume the hash');
    }

    public function test_resend_endpoint_emails_fresh_code_for_unverified_user(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Dan',
            'email' => 'dan@example.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'terms_accepted' => true,
            'age_confirmed' => true,
        ])->assertStatus(201);

        $user = User::query()->where('email', 'dan@example.com')->firstOrFail();
        $token = $user->createToken('test', ['*'])->plainTextToken;
        Mail::assertSent(EmailVerificationCodeMail::class, 1);

        $this->withToken($token)
            ->postJson('/api/account/verify-email/resend')
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(EmailVerificationCodeMail::class, 2);
    }

    public function test_verify_email_no_op_for_already_verified(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/account/verify-email', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.verified', true);
    }
}
