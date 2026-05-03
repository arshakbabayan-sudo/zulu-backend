<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTwoFactor;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the Sprint 62 admin security/MFA endpoints.
 */
class AdminSecurityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_index_requires_platform_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/platform-admin/security/two-factor')->assertStatus(403);
    }

    public function test_stats_returns_coverage_pct(): void
    {
        $admin = $this->createPlatformAdmin();
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();

        UserTwoFactor::query()->create([
            'user_id' => $u1->id,
            'secret_encrypted' => encrypt('secret-1'),
            'enabled_at' => now(),
            'confirmed_at' => now(),
        ]);
        UserTwoFactor::query()->create([
            'user_id' => $u2->id,
            'secret_encrypted' => encrypt('secret-2'),
            'enabled_at' => now(),
            'confirmed_at' => null, // pending
        ]);
        // u3 has no 2FA

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/security/stats');
        $response->assertOk();

        $this->assertSame(1, $response->json('data.two_factor_confirmed'));
        $this->assertSame(1, $response->json('data.two_factor_pending'));
        $this->assertGreaterThan(0, $response->json('data.two_factor_coverage_pct'));
    }

    public function test_force_disable_2fa_removes_row(): void
    {
        $admin = $this->createPlatformAdmin();
        $user = User::factory()->create();
        UserTwoFactor::query()->create([
            'user_id' => $user->id,
            'secret_encrypted' => encrypt('secret'),
            'enabled_at' => now(),
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/platform-admin/security/users/{$user->id}/force-disable-2fa");
        $response->assertOk();
        $this->assertTrue($response->json('data.two_factor_removed'));
        $this->assertSame(0, UserTwoFactor::query()->where('user_id', $user->id)->count());
    }

    public function test_force_disable_2fa_returns_404_for_users_without_2fa(): void
    {
        $admin = $this->createPlatformAdmin();
        $user = User::factory()->create();

        Sanctum::actingAs($admin);
        $this->postJson("/api/platform-admin/security/users/{$user->id}/force-disable-2fa")
            ->assertStatus(404);
    }

    public function test_force_logout_revokes_tokens(): void
    {
        $admin = $this->createPlatformAdmin();
        $user = User::factory()->create();

        // Create some tokens for user.
        $user->createToken('a');
        $user->createToken('b');
        $user->createToken('c');
        $this->assertSame(3, $user->tokens()->count());

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/platform-admin/security/users/{$user->id}/force-logout");
        $response->assertOk();
        $this->assertSame(3, $response->json('data.tokens_revoked'));
        $this->assertSame(0, $user->tokens()->count());
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}
