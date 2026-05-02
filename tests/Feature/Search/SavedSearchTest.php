<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Services\Search\SavedSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_user_saved_searches(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        app(SavedSearchService::class)->save($alice, ['name' => 'A1', 'query_string' => 'paris']);
        app(SavedSearchService::class)->save($alice, ['name' => 'A2', 'query_string' => 'london']);
        app(SavedSearchService::class)->save($bob, ['name' => 'B1', 'query_string' => 'rome']);

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/customer/saved-searches');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_store_creates_saved_search(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/customer/saved-searches', [
            'name' => 'Paris in December',
            'module_type' => 'package',
            'query_string' => 'paris december',
            'filters' => ['price_max' => 1500],
            'alerts_enabled' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Paris in December');
        $response->assertJsonPath('data.alerts_enabled', true);
    }

    public function test_toggle_alerts_404_for_other_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobSearch = app(SavedSearchService::class)->save($bob, ['query_string' => 'rome']);

        Sanctum::actingAs($alice);
        $this->patchJson('/api/customer/saved-searches/'.$bobSearch->id.'/alerts', [
            'alerts_enabled' => true,
        ])->assertStatus(404);
    }

    public function test_toggle_alerts_works(): void
    {
        $user = User::factory()->create();
        $saved = app(SavedSearchService::class)->save($user, ['query_string' => 'paris', 'alerts_enabled' => false]);

        Sanctum::actingAs($user);
        $response = $this->patchJson('/api/customer/saved-searches/'.$saved->id.'/alerts', ['alerts_enabled' => true]);

        $response->assertOk();
        $this->assertTrue($response->json('data.alerts_enabled'));
    }

    public function test_destroy_removes_search(): void
    {
        $user = User::factory()->create();
        $saved = app(SavedSearchService::class)->save($user, ['query_string' => 'paris']);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/customer/saved-searches/'.$saved->id)->assertOk();
        $this->assertCount(0, $this->getJson('/api/customer/saved-searches')->json('data'));
    }

    public function test_autocomplete_returns_prefix_matches_ordered_by_frequency(): void
    {
        $service = app(SavedSearchService::class);
        // log paris 3 times, lisbon 1 time, london 2 times
        for ($i = 0; $i < 3; $i++) {
            $service->logQuery(null, 'paris', 'package', [], 5);
        }
        $service->logQuery(null, 'liverpool', 'hotel', [], 5);
        for ($i = 0; $i < 2; $i++) {
            $service->logQuery(null, 'lisbon', 'hotel', [], 5);
        }

        $response = $this->getJson('/api/search/autocomplete?q=li');

        $response->assertOk();
        $suggestions = $response->json('data');
        $this->assertContains('lisbon', $suggestions);
        $this->assertContains('liverpool', $suggestions);
        // lisbon should come first (2 > 1)
        $this->assertSame('lisbon', $suggestions[0]);
    }

    public function test_autocomplete_too_short_returns_empty(): void
    {
        $response = $this->getJson('/api/search/autocomplete?q=a');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_popular_returns_aggregated_counts(): void
    {
        $service = app(SavedSearchService::class);
        for ($i = 0; $i < 5; $i++) {
            $service->logQuery(null, 'paris', 'package', [], 5);
        }
        for ($i = 0; $i < 3; $i++) {
            $service->logQuery(null, 'london', 'hotel', [], 5);
        }

        $response = $this->getJson('/api/search/popular');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('paris', $data[0]['query_string']);
        $this->assertSame(5, $data[0]['count']);
    }
}
