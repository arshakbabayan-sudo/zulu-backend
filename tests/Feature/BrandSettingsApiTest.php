<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);

        // Manually seed the brand_settings row so tests don't depend on
        // migration order in the test DB.
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'brand_settings'],
            [
                'value' => json_encode([
                    'logo_url' => '/brand/logo-zulu.svg',
                    'emblem_url' => '/brand/zulu-emblem.svg',
                    'favicon_url' => '/favicon.svg',
                    'phone' => null,
                    'email' => null,
                    'address' => null,
                    'address_city' => 'Yerevan',
                    'address_country' => 'Armenia',
                    'social_links' => [
                        'facebook' => null,
                        'instagram' => null,
                        'linkedin' => null,
                    ],
                    'custom_fields' => [],
                ]),
                'type' => 'json',
                'description' => 'Brand settings',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function test_public_can_read_brand_settings(): void
    {
        $resp = $this->getJson('/api/brand-settings')->assertOk();
        $resp->assertJsonPath('data.logo_url', '/brand/logo-zulu.svg');
        $resp->assertJsonPath('data.address_city', 'Yerevan');
        $resp->assertJsonPath('data.social_links.facebook', null);
    }

    public function test_admin_can_partial_update(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $resp = $this->patchJson('/api/platform-admin/brand-settings', [
            'phone' => '+374 11 123 456',
            'email' => 'info@zulu.am',
            'social_links' => [
                'instagram' => 'https://instagram.com/zulu',
            ],
        ])->assertOk();

        $resp->assertJsonPath('data.phone', '+374 11 123 456');
        $resp->assertJsonPath('data.email', 'info@zulu.am');
        $resp->assertJsonPath('data.social_links.instagram', 'https://instagram.com/zulu');
        // Reserved imagery fields preserved
        $resp->assertJsonPath('data.logo_url', '/brand/logo-zulu.svg');
        // Other social platforms preserved
        $resp->assertJsonPath('data.social_links.facebook', null);
    }

    public function test_admin_can_add_custom_field(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/platform-admin/brand-settings', [
            'custom_fields' => [
                ['key' => 'office_hours', 'label' => 'Office hours', 'type' => 'text', 'value' => 'Mon-Fri 09:00-18:00'],
                ['key' => 'support_url', 'label' => 'Support', 'type' => 'url', 'value' => 'https://help.zulu.am'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.custom_fields.0.key', 'office_hours')
            ->assertJsonPath('data.custom_fields.0.value', 'Mon-Fri 09:00-18:00')
            ->assertJsonPath('data.custom_fields.1.type', 'url');
    }

    public function test_custom_field_duplicate_key_is_rejected(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/platform-admin/brand-settings', [
            'custom_fields' => [
                ['key' => 'duplicate_key', 'label' => 'A', 'type' => 'text', 'value' => 'a'],
                ['key' => 'duplicate_key', 'label' => 'B', 'type' => 'text', 'value' => 'b'],
            ],
        ])->assertStatus(422);
    }

    public function test_custom_field_unknown_type_rejected(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/platform-admin/brand-settings', [
            'custom_fields' => [
                ['key' => 'weird', 'label' => 'Weird', 'type' => 'sql_injection', 'value' => 'pwn'],
            ],
        ])->assertStatus(422);
    }

    public function test_non_admin_cannot_update(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/platform-admin/brand-settings', [
            'phone' => '+374 11 999 999',
        ])->assertStatus(403);
    }

    public function test_invalid_email_rejected(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/platform-admin/brand-settings', [
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }
}
