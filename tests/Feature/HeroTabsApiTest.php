<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeroTabsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'hero_tabs_config'],
            [
                'value' => json_encode([
                    ['slug' => 'flights', 'label_key' => 'home.search.tab.flights', 'position' => 1, 'is_active' => true],
                    ['slug' => 'stays', 'label_key' => 'home.search.tab.stays', 'position' => 2, 'is_active' => true],
                    ['slug' => 'cars', 'label_key' => 'home.search.tab.cars', 'position' => 3, 'is_active' => false],
                ]),
                'type' => 'json',
            ]
        );
    }

    public function test_show_returns_only_active_tabs_sorted_by_position(): void
    {
        $resp = $this->getJson('/api/hero-tabs?lang=en')->assertOk();
        $tabs = $resp->json('data.tabs');

        $this->assertCount(2, $tabs);
        $this->assertSame('flights', $tabs[0]['slug']);
        $this->assertSame('stays', $tabs[1]['slug']);
        $this->assertSame(1, $tabs[0]['position']);
        $this->assertSame(2, $tabs[1]['position']);
        $this->assertSame('en', $resp->json('data.lang'));
    }

    public function test_admin_update_replaces_config(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $payload = [
            'tabs' => [
                ['slug' => 'flights', 'label_key' => 'home.search.tab.flights', 'position' => 1, 'is_active' => true],
                ['slug' => 'charter_jets', 'label_key' => 'home.search.tab.charter_jets', 'position' => 2, 'is_active' => true],
            ],
        ];

        $this->patchJson('/api/platform-admin/site-settings/hero-tabs', $payload)
            ->assertOk()
            ->assertJsonPath('data.tabs.1.slug', 'charter_jets');

        // Verify public read reflects the update
        $public = $this->getJson('/api/hero-tabs')->assertOk();
        $slugs = array_column($public->json('data.tabs'), 'slug');
        $this->assertSame(['flights', 'charter_jets'], $slugs);
    }

    public function test_admin_update_rejects_duplicate_slugs(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/platform-admin/site-settings/hero-tabs', [
            'tabs' => [
                ['slug' => 'flights', 'label_key' => 'k1', 'position' => 1, 'is_active' => true],
                ['slug' => 'flights', 'label_key' => 'k2', 'position' => 2, 'is_active' => true],
            ],
        ])->assertStatus(422);
    }

    public function test_admin_update_rejects_unauthenticated(): void
    {
        $this->patchJson('/api/platform-admin/site-settings/hero-tabs', [
            'tabs' => [
                ['slug' => 'flights', 'label_key' => 'k', 'position' => 1, 'is_active' => true],
            ],
        ])->assertStatus(401);
    }
}
