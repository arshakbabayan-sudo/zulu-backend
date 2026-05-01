<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminContractTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/platform-admin/contract-templates')->assertStatus(403);
    }

    public function test_index_returns_templates_with_filters(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->createTemplate(['type' => 'platform', 'language' => 'en']);
        $this->createTemplate(['type' => 'platform', 'language' => 'hy']);
        $this->createTemplate(['type' => 'partner_default', 'language' => 'en']);

        Sanctum::actingAs($admin);

        $all = $this->getJson('/api/platform-admin/contract-templates');
        $all->assertOk();
        $this->assertCount(3, $all->json('data'));

        $platform = $this->getJson('/api/platform-admin/contract-templates?type=platform');
        $this->assertCount(2, $platform->json('data'));

        $en = $this->getJson('/api/platform-admin/contract-templates?language=en');
        $this->assertCount(2, $en->json('data'));
    }

    public function test_show_returns_single_template(): void
    {
        $admin = $this->createPlatformAdmin();
        $template = $this->createTemplate();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/contract-templates/'.$template->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $template->id);
    }

    public function test_show_404_for_unknown(): void
    {
        $admin = $this->createPlatformAdmin();
        Sanctum::actingAs($admin);

        $unknown = '00000000-0000-0000-0000-000000000000';
        $this->getJson('/api/platform-admin/contract-templates/'.$unknown)->assertStatus(404);
    }

    public function test_store_creates_template(): void
    {
        $admin = $this->createPlatformAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/platform-admin/contract-templates', [
            'name' => 'New PG Template',
            'type' => 'platform',
            'language' => 'ru',
            'body' => 'Hi {{seller_name}}',
            'variables' => [['key' => 'seller_name', 'label' => 'Seller', 'type' => 'string']],
            'active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'New PG Template');
        $this->assertSame(1, ContractTemplate::query()->count());
    }

    public function test_update_bumps_version_when_body_changes(): void
    {
        $admin = $this->createPlatformAdmin();
        $template = $this->createTemplate(['version' => 1, 'body' => 'Original body']);

        Sanctum::actingAs($admin);
        $response = $this->patchJson('/api/platform-admin/contract-templates/'.$template->id, [
            'body' => 'Revised body v2',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.version', 2);
        $response->assertJsonPath('data.body', 'Revised body v2');
    }

    public function test_update_does_not_bump_version_when_only_name_changes(): void
    {
        $admin = $this->createPlatformAdmin();
        $template = $this->createTemplate(['version' => 5]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson('/api/platform-admin/contract-templates/'.$template->id, [
            'name' => 'Renamed only',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.version', 5);
        $response->assertJsonPath('data.name', 'Renamed only');
    }

    public function test_store_validates_type(): void
    {
        $admin = $this->createPlatformAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/platform-admin/contract-templates', [
            'name' => 'Invalid type test',
            'type' => 'bogus',
            'language' => 'en',
            'body' => 'x',
        ])->assertStatus(422);
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTemplate(array $overrides = []): ContractTemplate
    {
        return ContractTemplate::query()->create(array_merge([
            'name' => 'T '.str()->random(6),
            'type' => 'platform',
            'language' => 'en',
            'version' => 1,
            'body' => 'Body {{x}}',
            'variables' => [],
            'active' => true,
        ], $overrides));
    }
}
