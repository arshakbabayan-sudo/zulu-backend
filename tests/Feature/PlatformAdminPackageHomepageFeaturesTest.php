<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Package;
use App\Models\PackageHomepageFeature;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAdminPackageHomepageFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
    }

    private function makePackage(): Package
    {
        $company = Company::create([
            'name' => 'Operator Co',
            'type' => 'operator',
            'status' => 'active',
            'slug' => 'operator-co-'.uniqid(),
        ]);

        $offer = Offer::create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Trip',
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        return Package::create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Trip',
            'package_subtitle' => 'Sub',
            'destination_country' => 'Armenia',
            'destination_city' => 'Yerevan',
            'duration_days' => 5,
            'base_price' => 500,
            'display_price_mode' => 'total',
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => true,
            'is_package_eligible' => false,
            'status' => 'active',
            'main_image' => 'packages/test.jpg',
        ]);
    }

    public function test_admin_lists_empty_then_syncs_features(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $pkg = $this->makePackage();

        $this->getJson("/api/platform-admin/packages/{$pkg->id}/homepage-features")
            ->assertOk()
            ->assertJsonPath('data.features', [])
            ->assertJsonPath('data.sections', PackageHomepageFeature::SECTIONS);

        $this->putJson("/api/platform-admin/packages/{$pkg->id}/homepage-features", [
            'features' => [
                ['section_slug' => 'special_offers', 'position' => 1, 'is_active' => true],
                ['section_slug' => 'popular_destinations', 'position' => 2, 'is_active' => false],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'data.features');

        $this->assertDatabaseHas('package_homepage_features', [
            'package_id' => $pkg->id,
            'section_slug' => 'special_offers',
            'position' => 1,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('package_homepage_features', [
            'package_id' => $pkg->id,
            'section_slug' => 'popular_destinations',
            'position' => 2,
            'is_active' => false,
        ]);
    }

    public function test_sync_removes_sections_not_in_payload(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $pkg = $this->makePackage();

        PackageHomepageFeature::create([
            'package_id' => $pkg->id,
            'section_slug' => 'special_offers',
            'position' => 1,
            'is_active' => true,
        ]);
        PackageHomepageFeature::create([
            'package_id' => $pkg->id,
            'section_slug' => 'popular_destinations',
            'position' => 1,
            'is_active' => true,
        ]);

        $this->putJson("/api/platform-admin/packages/{$pkg->id}/homepage-features", [
            'features' => [
                ['section_slug' => 'special_offers', 'position' => 3, 'is_active' => true],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('package_homepage_features', [
            'package_id' => $pkg->id,
            'section_slug' => 'special_offers',
            'position' => 3,
        ]);
        $this->assertDatabaseMissing('package_homepage_features', [
            'package_id' => $pkg->id,
            'section_slug' => 'popular_destinations',
        ]);
    }

    public function test_invalid_section_slug_is_rejected(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $pkg = $this->makePackage();

        $this->putJson("/api/platform-admin/packages/{$pkg->id}/homepage-features", [
            'features' => [
                ['section_slug' => 'made_up_section', 'position' => 1, 'is_active' => true],
            ],
        ])->assertStatus(422);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $pkg = $this->makePackage();

        $this->getJson("/api/platform-admin/packages/{$pkg->id}/homepage-features")
            ->assertStatus(403);
        $this->putJson("/api/platform-admin/packages/{$pkg->id}/homepage-features", [
            'features' => [],
        ])->assertStatus(403);
    }
}
