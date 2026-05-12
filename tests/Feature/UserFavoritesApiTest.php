<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserFavoritesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/user/favorites')->assertStatus(401);
    }

    public function test_store_creates_a_favorite(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/user/favorites', [
            'item_type' => 'hotel',
            'item_id' => 42,
        ])->assertCreated()
            ->assertJsonPath('data.item_type', 'hotel')
            ->assertJsonPath('data.item_id', 42);

        $this->assertDatabaseHas('user_favorites', [
            'user_id' => $user->id,
            'item_type' => 'hotel',
            'item_id' => 42,
        ]);
    }

    public function test_store_is_idempotent(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/user/favorites', ['item_type' => 'package', 'item_id' => 7])->assertCreated();
        $this->postJson('/api/user/favorites', ['item_type' => 'package', 'item_id' => 7])->assertOk();

        $this->assertSame(1, UserFavorite::query()
            ->where('user_id', $user->id)
            ->where('item_type', 'package')
            ->where('item_id', 7)
            ->count());
    }

    public function test_index_returns_only_callers_favorites(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        UserFavorite::create(['user_id' => $alice->id, 'item_type' => 'hotel', 'item_id' => 1]);
        UserFavorite::create(['user_id' => $bob->id, 'item_type' => 'hotel', 'item_id' => 2]);

        Sanctum::actingAs($alice);
        $resp = $this->getJson('/api/user/favorites')->assertOk();
        $this->assertCount(1, $resp->json('data'));
        $this->assertSame(1, $resp->json('data.0.item_id'));
    }

    public function test_destroy_removes_a_favorite(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        UserFavorite::create(['user_id' => $user->id, 'item_type' => 'excursion', 'item_id' => 99]);

        $this->deleteJson('/api/user/favorites/excursion/99')->assertOk()->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('user_favorites', [
            'user_id' => $user->id, 'item_type' => 'excursion', 'item_id' => 99,
        ]);
    }

    public function test_store_validates_item_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/user/favorites', ['item_type' => 'galaxy', 'item_id' => 1])
            ->assertStatus(422);
    }
}
