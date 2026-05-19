<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.14 feature tests — per-user PIN for sensitive admin actions.
 *
 * Routes:
 *   GET    /api/account/pin           pinStatus
 *   POST   /api/account/pin           setPin (set + rotate)
 *   POST   /api/account/pin/verify    verifyPin
 *   DELETE /api/account/pin           clearPin
 *
 * Critical paths to lock down:
 *   - Setting a PIN requires the current account password.
 *   - Rotating a PIN requires both the password AND the previous PIN.
 *   - verifyPin never reveals which user has a PIN; mismatch returns 422.
 *   - Clearing a PIN requires the password.
 *   - pin_hash is never returned in any response.
 */
class AccountPinTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_PASSWORD = 'pin-test-password-1!';

    public function test_unauthenticated_caller_is_rejected_on_every_endpoint(): void
    {
        $this->getJson('/api/account/pin')->assertStatus(401);
        $this->postJson('/api/account/pin', [])->assertStatus(401);
        $this->postJson('/api/account/pin/verify', [])->assertStatus(401);
        $this->deleteJson('/api/account/pin')->assertStatus(401);
    }

    public function test_status_returns_is_set_false_for_new_user(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/account/pin');

        $response->assertOk();
        $response->assertJsonPath('data.is_set', false);
        $response->assertJsonPath('data.set_at', null);
    }

    public function test_set_pin_requires_correct_password(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/account/pin', [
            'password' => 'wrong-password',
            'new_pin' => '1234',
        ])->assertStatus(422)->assertJsonPath('message', 'Incorrect account password');

        // PIN must not have been persisted.
        $user = User::query()->first();
        $this->assertNull($user?->pin_hash);
        $this->assertNull($user?->pin_set_at);
    }

    public function test_set_pin_persists_hash_and_set_at(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/account/pin', [
            'password' => self::ACCOUNT_PASSWORD,
            'new_pin' => '8642',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_set', true);
        $this->assertNotNull($response->json('data.set_at'));

        // The plain PIN never leaks into the response.
        $this->assertStringNotContainsString('8642', $response->getContent());

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->pin_hash);
        $this->assertTrue(Hash::check('8642', (string) $fresh->pin_hash));
    }

    public function test_set_pin_validates_format(): void
    {
        Sanctum::actingAs($this->makeUser());

        // Non-numeric
        $this->postJson('/api/account/pin', [
            'password' => self::ACCOUNT_PASSWORD,
            'new_pin' => 'abcd',
        ])->assertStatus(422)->assertJsonValidationErrors(['new_pin']);

        // Too short
        $this->postJson('/api/account/pin', [
            'password' => self::ACCOUNT_PASSWORD,
            'new_pin' => '12',
        ])->assertStatus(422)->assertJsonValidationErrors(['new_pin']);
    }

    public function test_rotating_pin_requires_current_pin(): void
    {
        $user = $this->makeUser();
        $user->pin_hash = Hash::make('1111');
        $user->pin_set_at = now();
        $user->save();
        Sanctum::actingAs($user);

        // Missing current_pin → reject
        $this->postJson('/api/account/pin', [
            'password' => self::ACCOUNT_PASSWORD,
            'new_pin' => '2222',
        ])->assertStatus(422);

        // Wrong current_pin → reject
        $this->postJson('/api/account/pin', [
            'password' => self::ACCOUNT_PASSWORD,
            'current_pin' => '9999',
            'new_pin' => '2222',
        ])->assertStatus(422)->assertJsonPath('message', 'Current PIN incorrect');

        // Correct current_pin → accept
        $this->postJson('/api/account/pin', [
            'password' => self::ACCOUNT_PASSWORD,
            'current_pin' => '1111',
            'new_pin' => '2222',
        ])->assertOk();

        $this->assertTrue(Hash::check('2222', (string) $user->fresh()->pin_hash));
    }

    public function test_verify_pin_returns_success_on_match(): void
    {
        $user = $this->makeUser();
        $user->pin_hash = Hash::make('5050');
        $user->pin_set_at = now();
        $user->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/account/pin/verify', ['pin' => '5050'])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->postJson('/api/account/pin/verify', ['pin' => '1234'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'PIN incorrect');
    }

    public function test_verify_pin_without_pin_set_returns_422(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/account/pin/verify', ['pin' => '1234'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'PIN not set');
    }

    public function test_clear_pin_requires_password_then_wipes_hash(): void
    {
        $user = $this->makeUser();
        $user->pin_hash = Hash::make('7777');
        $user->pin_set_at = now();
        $user->save();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/account/pin', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->deleteJson('/api/account/pin', ['password' => self::ACCOUNT_PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.is_set', false);

        $fresh = $user->fresh();
        $this->assertNull($fresh->pin_hash);
        $this->assertNull($fresh->pin_set_at);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.14 Test',
            'email' => 'p714-'.str()->uuid().'@example.test',
            'password' => bcrypt(self::ACCOUNT_PASSWORD),
            'status' => User::STATUS_ACTIVE,
        ]);
    }
}
