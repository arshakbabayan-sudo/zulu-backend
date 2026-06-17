<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/device-tokens', ['token' => 'abc'])->assertStatus(401);
    }

    public function test_registers_a_device_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/device-tokens', ['token' => 'tok-123', 'platform' => 'web'])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'tok-123',
            'user_id' => $user->id,
            'platform' => 'web',
        ]);
    }

    public function test_reregistering_a_token_repoints_it_without_duplicating(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson('/api/device-tokens', ['token' => 'shared-tok', 'platform' => 'web'])->assertStatus(201);

        Sanctum::actingAs($second);
        $this->postJson('/api/device-tokens', ['token' => 'shared-tok', 'platform' => 'android'])->assertStatus(201);

        $this->assertSame(1, DeviceToken::query()->where('token', 'shared-tok')->count());
        $this->assertDatabaseHas('device_tokens', [
            'token' => 'shared-tok',
            'user_id' => $second->id,
            'platform' => 'android',
        ]);
    }

    public function test_rejects_unknown_platform(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/device-tokens', ['token' => 'tok', 'platform' => 'desktop'])
            ->assertStatus(422);
    }

    public function test_deletes_only_the_callers_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'mine', 'platform' => 'web']);
        DeviceToken::query()->create(['user_id' => $other->id, 'token' => 'theirs', 'platform' => 'web']);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/device-tokens', ['token' => 'mine'])->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'mine']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'theirs']);
    }
}
