<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Feature tests — multi-device session list + self-service password change.
 *
 * Routes covered:
 *   GET    /api/account/sessions             listSessions
 *   DELETE /api/account/sessions/{id}        revokeSession
 *   POST   /api/account/change-password      changePassword
 *
 * Critical guarantees:
 *   - Current token is flagged is_current=true; can't be revoked here.
 *   - Revoking another token deletes that token row.
 *   - Wrong current password returns 422 without rotating.
 *   - Successful change keeps the current token and revokes all others.
 */
class AccountSessionsAndPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_PASSWORD = 'old-password-123!';

    public function test_unauthenticated_caller_is_rejected_on_every_endpoint(): void
    {
        $this->getJson('/api/account/sessions')->assertStatus(401);
        $this->deleteJson('/api/account/sessions/1')->assertStatus(401);
        $this->postJson('/api/account/change-password', [])->assertStatus(401);
    }

    public function test_sessions_endpoint_lists_user_tokens_with_current_flag(): void
    {
        $user = $this->makeUser();
        $current = $user->createToken('Chrome on Windows');
        $other = $user->createToken('Mobile Safari');

        $response = $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->getJson('/api/account/sessions');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(2, $rows);

        $byId = collect($rows)->keyBy('id');
        $this->assertTrue((bool) $byId[$current->accessToken->id]['is_current']);
        $this->assertFalse((bool) $byId[$other->accessToken->id]['is_current']);
        $this->assertSame('Chrome on Windows', $byId[$current->accessToken->id]['name']);
    }

    public function test_revoke_session_deletes_other_token_but_not_current(): void
    {
        $user = $this->makeUser();
        $current = $user->createToken('Current');
        $other = $user->createToken('Other');

        // Revoking another token works.
        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->deleteJson('/api/account/sessions/'.$other->accessToken->id)
            ->assertOk();

        $this->assertNull(PersonalAccessToken::query()->find($other->accessToken->id));

        // Revoking the current token returns 422.
        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->deleteJson('/api/account/sessions/'.$current->accessToken->id)
            ->assertStatus(422);

        $this->assertNotNull(PersonalAccessToken::query()->find($current->accessToken->id));
    }

    public function test_revoke_session_404_for_other_users_token(): void
    {
        $user = $this->makeUser();
        $stranger = $this->makeUser();
        $strangerToken = $stranger->createToken('Stranger');
        $myToken = $user->createToken('Mine');

        $this->withHeader('Authorization', 'Bearer '.$myToken->plainTextToken)
            ->deleteJson('/api/account/sessions/'.$strangerToken->accessToken->id)
            ->assertStatus(404);

        // Stranger's token must still exist.
        $this->assertNotNull(PersonalAccessToken::query()->find($strangerToken->accessToken->id));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser();
        $tok = $user->createToken('cur');

        $this->withHeader('Authorization', 'Bearer '.$tok->plainTextToken)
            ->postJson('/api/account/change-password', [
                'current_password' => 'wrong-password',
                'new_password' => 'new-password-456!',
                'new_password_confirmation' => 'new-password-456!',
            ])
            ->assertStatus(422);

        // Password unchanged.
        $this->assertTrue(Hash::check(self::ACCOUNT_PASSWORD, $user->fresh()->password));
    }

    public function test_change_password_requires_confirmation_match(): void
    {
        $user = $this->makeUser();
        $tok = $user->createToken('cur');

        $this->withHeader('Authorization', 'Bearer '.$tok->plainTextToken)
            ->postJson('/api/account/change-password', [
                'current_password' => self::ACCOUNT_PASSWORD,
                'new_password' => 'new-password-456!',
                'new_password_confirmation' => 'different',
            ])
            ->assertStatus(422);
    }

    public function test_change_password_rotates_and_revokes_other_sessions(): void
    {
        $user = $this->makeUser();
        $current = $user->createToken('Current');
        $other1 = $user->createToken('Other 1');
        $other2 = $user->createToken('Other 2');

        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->postJson('/api/account/change-password', [
                'current_password' => self::ACCOUNT_PASSWORD,
                'new_password' => 'new-password-456!',
                'new_password_confirmation' => 'new-password-456!',
            ])
            ->assertOk()
            ->assertJsonPath('data.revoked_other_sessions', 2);

        // New password persisted.
        $this->assertTrue(Hash::check('new-password-456!', $user->fresh()->password));
        $this->assertFalse(Hash::check(self::ACCOUNT_PASSWORD, $user->fresh()->password));

        // Current token still valid; others deleted.
        $this->assertNotNull(PersonalAccessToken::query()->find($current->accessToken->id));
        $this->assertNull(PersonalAccessToken::query()->find($other1->accessToken->id));
        $this->assertNull(PersonalAccessToken::query()->find($other2->accessToken->id));
    }

    public function test_change_password_rejects_same_password(): void
    {
        $user = $this->makeUser();
        $tok = $user->createToken('cur');

        $this->withHeader('Authorization', 'Bearer '.$tok->plainTextToken)
            ->postJson('/api/account/change-password', [
                'current_password' => self::ACCOUNT_PASSWORD,
                'new_password' => self::ACCOUNT_PASSWORD,
                'new_password_confirmation' => self::ACCOUNT_PASSWORD,
            ])
            ->assertStatus(422);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Sessions Test',
            'email' => 'sess-'.str()->uuid().'@example.test',
            'password' => bcrypt(self::ACCOUNT_PASSWORD),
            'status' => User::STATUS_ACTIVE,
        ]);
    }
}
