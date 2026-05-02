<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Security\TotpGenerator;
use App\Services\Security\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $service;

    private TotpGenerator $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TwoFactorService::class);
        $this->totp = app(TotpGenerator::class);
    }

    // === TOTP generator unit-style tests ===

    public function test_totp_generates_secret_in_base32_format(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function test_totp_verify_accepts_current_code_and_rejects_random(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->codeAt($secret, (int) floor(time() / TotpGenerator::PERIOD));

        $this->assertTrue($this->totp->verify($secret, $code));
        $this->assertFalse($this->totp->verify($secret, '000000'));
    }

    public function test_totp_verify_tolerates_one_step_drift(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $previousStep = (int) floor(($now - 30) / TotpGenerator::PERIOD);
        $code = $this->totp->codeAt($secret, $previousStep);

        $this->assertTrue($this->totp->verify($secret, $code, $now));
    }

    public function test_totp_provisioning_uri_contains_required_params(): void
    {
        $uri = $this->totp->provisioningUri('alice@example.com', 'ZULU', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('issuer=ZULU', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('digits=6', $uri);
    }

    // === Service ===

    public function test_setup_returns_secret_qr_uri_and_recovery_codes(): void
    {
        $user = User::factory()->create();
        $payload = $this->service->setup($user);

        $this->assertNotEmpty($payload['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $payload['qr_uri']);
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $payload['recovery_codes']);
        $this->assertFalse($this->service->isEnabled($user));
    }

    public function test_setup_throws_if_already_enabled(): void
    {
        $user = User::factory()->create();
        $payload = $this->service->setup($user);
        $this->service->confirm($user, $this->currentCode($payload['secret']));

        $this->expectException(\RuntimeException::class);
        $this->service->setup($user);
    }

    public function test_confirm_with_correct_code_enables_2fa(): void
    {
        $user = User::factory()->create();
        $payload = $this->service->setup($user);

        $ok = $this->service->confirm($user, $this->currentCode($payload['secret']));

        $this->assertTrue($ok);
        $this->assertTrue($this->service->isEnabled($user));
    }

    public function test_confirm_with_wrong_code_fails(): void
    {
        $user = User::factory()->create();
        $this->service->setup($user);

        $this->assertFalse($this->service->confirm($user, '000000'));
        $this->assertFalse($this->service->isEnabled($user));
    }

    public function test_verify_with_totp_code_succeeds_for_enabled_user(): void
    {
        $user = User::factory()->create();
        $payload = $this->service->setup($user);
        $this->service->confirm($user, $this->currentCode($payload['secret']));

        $this->assertTrue($this->service->verify($user, $this->currentCode($payload['secret'])));
    }

    public function test_verify_with_recovery_code_consumes_it_one_time(): void
    {
        $user = User::factory()->create();
        $payload = $this->service->setup($user);
        $this->service->confirm($user, $this->currentCode($payload['secret']));

        $recoveryCode = $payload['recovery_codes'][0];

        $this->assertTrue($this->service->verify($user, $recoveryCode));
        // Second use of same recovery code must fail
        $this->assertFalse($this->service->verify($user, $recoveryCode));
    }

    public function test_disable_requires_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correctpw')]);
        $payload = $this->service->setup($user);
        $this->service->confirm($user, $this->currentCode($payload['secret']));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->disable($user, 'wrongpw');
    }

    public function test_disable_with_correct_password_removes_record(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correctpw')]);
        $payload = $this->service->setup($user);
        $this->service->confirm($user, $this->currentCode($payload['secret']));

        $this->assertTrue($this->service->disable($user, 'correctpw'));
        $this->assertFalse($this->service->isEnabled($user));
    }

    // === API ===

    public function test_status_returns_enabled_flag(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/account/2fa/status');
        $response->assertOk();
        $this->assertFalse($response->json('data.enabled'));
    }

    public function test_setup_then_confirm_via_api(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $setup = $this->postJson('/api/account/2fa/setup');
        $setup->assertStatus(201);
        $secret = $setup->json('data.secret');

        $confirm = $this->postJson('/api/account/2fa/confirm', ['code' => $this->currentCode($secret)]);
        $confirm->assertOk();

        $status = $this->getJson('/api/account/2fa/status');
        $this->assertTrue($status->json('data.enabled'));
    }

    public function test_disable_api_requires_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('rightpw')]);
        $payload = $this->service->setup($user);
        $this->service->confirm($user, $this->currentCode($payload['secret']));

        Sanctum::actingAs($user);
        $this->postJson('/api/account/2fa/disable', ['password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/account/2fa/disable', ['password' => 'rightpw'])->assertOk();
    }

    public function test_regenerate_recovery_codes_returns_fresh_set(): void
    {
        $user = User::factory()->create();
        $payload = $this->service->setup($user);
        $this->service->confirm($user, $this->currentCode($payload['secret']));

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/account/2fa/recovery-codes/regenerate');

        $response->assertOk();
        $codes = $response->json('data.recovery_codes');
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $codes);

        // Old codes from setup must no longer work
        $this->assertFalse($this->service->verify($user, $payload['recovery_codes'][0]));
        // New codes work
        $this->assertTrue($this->service->verify($user, $codes[0]));
    }

    private function currentCode(string $secret): string
    {
        return $this->totp->codeAt($secret, (int) floor(time() / TotpGenerator::PERIOD));
    }
}
