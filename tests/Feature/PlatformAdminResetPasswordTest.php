<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the super-admin-only password reset:
 *   POST /api/platform-admin/users/{id}/reset-password
 *
 * Contract:
 *   REQUEST  { new_password?: string|null }
 *              - non-empty string → used (min 8, else 422)
 *              - omitted / null   → backend generates a strong random password
 *   SUCCESS  { success:true, message, data:{ user_id, new_password } }
 *              new_password = the freshly-SET plaintext, returned once.
 *   SIDE EFF revoke the target's Sanctum tokens.
 *   ERRORS   403 (not super), 404 (no user), 422 (weak new_password).
 *
 * Also covers the additive "login_method" field on the user detail
 * response (GET /platform-admin/users/{id}).
 *
 * Roles live ONLY in the user_company.role_id pivot. SQLite CI enforces
 * FKs, so every Company/Role/User parent row is real.
 */
class PlatformAdminResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    // ── (a) explicit new_password → 200, echoes it, Hash::check passes ──
    public function test_super_admin_resets_with_explicit_password(): void
    {
        $super = $this->makeSuperAdmin();
        $target = $this->makeUser('reset-explicit@example.test');

        Sanctum::actingAs($super);

        $newPassword = 'Fresh-Passw0rd!';
        $response = $this->postJson("/api/platform-admin/users/{$target->id}/reset-password", [
            'new_password' => $newPassword,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'user_id' => $target->id,
                'new_password' => $newPassword,
            ],
        ]);

        // The stored (bcrypt) password now verifies against the plaintext we set.
        $this->assertTrue(Hash::check($newPassword, $target->fresh()->password));
    }

    // ── (b) omitted new_password → 200 with a generated >=12-char password ──
    public function test_super_admin_reset_generates_password_when_omitted(): void
    {
        $super = $this->makeSuperAdmin();
        $target = $this->makeUser('reset-generated@example.test');

        Sanctum::actingAs($super);

        $response = $this->postJson("/api/platform-admin/users/{$target->id}/reset-password", []);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $generated = $response->json('data.new_password');
        $this->assertIsString($generated);
        $this->assertGreaterThanOrEqual(12, strlen($generated));

        // The generated plaintext verifies against the freshly-stored hash.
        $this->assertTrue(Hash::check($generated, $target->fresh()->password));
    }

    // ── (c) resetting revokes the target's Sanctum tokens ──
    public function test_reset_revokes_target_tokens(): void
    {
        $super = $this->makeSuperAdmin();
        $target = $this->makeUser('reset-tokens@example.test');

        $targetToken = $target->createToken('Target session');
        $tokenId = $targetToken->accessToken->id;
        $this->assertNotNull(PersonalAccessToken::query()->find($tokenId));

        Sanctum::actingAs($super);

        $this->postJson("/api/platform-admin/users/{$target->id}/reset-password", [
            'new_password' => 'Another-Passw0rd!',
        ])->assertOk();

        // The target's token is gone.
        $this->assertNull(PersonalAccessToken::query()->find($tokenId));
    }

    // ── (d) non-super-admin caller → 403 ──
    public function test_non_super_admin_caller_is_403(): void
    {
        $company = $this->makeCompany();
        $adminRole = $this->makeRole('company_admin', Role::SCOPE_COMPANY);

        // A company_admin (admin-panel accessible, but NOT super).
        $companyAdmin = $this->makeUser('co-admin-reset@example.test');
        UserCompany::create([
            'user_id' => $companyAdmin->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
        ]);

        $target = $this->makeUser('reset-forbidden@example.test');

        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson("/api/platform-admin/users/{$target->id}/reset-password", [
            'new_password' => 'Should-Not-Work-1!',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    // ── (e) new_password shorter than 8 → 422 ──
    public function test_short_new_password_is_422(): void
    {
        $super = $this->makeSuperAdmin();
        $target = $this->makeUser('reset-short@example.test');

        Sanctum::actingAs($super);

        $response = $this->postJson("/api/platform-admin/users/{$target->id}/reset-password", [
            'new_password' => 'short',
        ]);

        $response->assertStatus(422);
    }

    // ── (f) login_method on GET /users/{id}: 'password' vs 'google' ──
    public function test_login_method_present_on_user_detail(): void
    {
        $super = $this->makeSuperAdmin();

        $passwordUser = $this->makeUser('login-password@example.test');
        $googleUser = $this->makeUser('login-google@example.test');
        $googleUser->oauth_provider = 'google';
        $googleUser->save();

        Sanctum::actingAs($super);

        $passwordResp = $this->getJson("/api/platform-admin/users/{$passwordUser->id}");
        $passwordResp->assertOk();
        $passwordResp->assertJsonPath('data.login_method', 'password');

        $googleResp = $this->getJson("/api/platform-admin/users/{$googleUser->id}");
        $googleResp->assertOk();
        $googleResp->assertJsonPath('data.login_method', 'google');
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function makeUser(?string $email = null): User
    {
        return User::query()->create([
            'name' => 'Reset Test',
            'email' => $email ?? 'reset-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeCompany(?string $name = null, string $type = 'operator'): Company
    {
        return Company::query()->create([
            'name' => $name ?? 'Reset Co '.str()->uuid(),
            'type' => $type,
        ]);
    }

    private function makeRole(string $name, string $scope): Role
    {
        return Role::query()->firstOrCreate(['name' => $name], ['scope' => $scope]);
    }

    private function makeSuperAdmin(): User
    {
        $user = $this->makeUser('super-'.str()->uuid().'@example.test');
        $role = $this->makeRole('super_admin', Role::SCOPE_PLATFORM);
        $platform = $this->makeCompany('Platform '.str()->uuid(), 'platform');
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $platform->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
