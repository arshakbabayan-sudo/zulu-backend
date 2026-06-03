<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAdminPartnerSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
    }

    public function test_platform_admin_can_toggle_partner_visibility(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $company = Company::create([
            'name' => 'ACME Tours',
            'type' => 'operator',
            'slug' => 'acme-tours',
            'logo' => null,
            'is_partner_visible' => false,
        ]);

        $resp = $this->patchJson("/api/platform-admin/companies/{$company->id}/partner-settings", [
            'is_partner_visible' => true,
            'logo' => 'https://cdn.example/operators/acme.png',
        ])->assertOk();

        $resp->assertJsonPath('data.is_partner_visible', true);
        $resp->assertJsonPath('data.logo', 'https://cdn.example/operators/acme.png');

        $company->refresh();
        $this->assertTrue((bool) $company->is_partner_visible);
        $this->assertSame('https://cdn.example/operators/acme.png', $company->logo);
    }

    public function test_partial_update_only_changes_provided_fields(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $company = Company::create([
            'name' => 'Partial Co',
            'type' => 'operator',
            'slug' => 'partial-co',
            'logo' => 'operators/keep.png',
            'is_partner_visible' => true,
        ]);

        $this->patchJson("/api/platform-admin/companies/{$company->id}/partner-settings", [
            'is_partner_visible' => false,
        ])->assertOk();

        $company->refresh();
        $this->assertFalse((bool) $company->is_partner_visible);
        $this->assertSame('operators/keep.png', $company->logo);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $company = Company::create([
            'name' => 'Locked Co',
            'type' => 'operator',
            'slug' => 'locked-co',
        ]);

        $this->patchJson("/api/platform-admin/companies/{$company->id}/partner-settings", [
            'is_partner_visible' => true,
        ])->assertStatus(403);
    }
}
