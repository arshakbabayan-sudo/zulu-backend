<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Package;
use App\Models\PackageHomepageFeature;
use App\Models\Page;
use Database\Seeders\WidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHomePageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WidgetSeeder::class);
    }

    public function test_home_page_returns_404_when_missing(): void
    {
        $this->getJson('/api/pages/home-page')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_home_page_returns_empty_sections_when_no_data(): void
    {
        $this->createHomePage();

        $resp = $this->getJson('/api/pages/home-page')->assertOk();
        $resp->assertJsonPath('data.page_slug', 'home-page');
        $resp->assertJsonPath('data.sections.special_offers', []);
        $resp->assertJsonPath('data.sections.popular_destinations', []);
        $resp->assertJsonPath('data.sections.partners', []);
    }

    public function test_home_page_returns_special_offers_packages(): void
    {
        $this->createHomePage();
        $operator = $this->makeOperator();
        $pkgFeatured = $this->makePackage($operator, 'Featured Trip');
        $pkgNotFeatured = $this->makePackage($operator, 'Other Trip');

        PackageHomepageFeature::create([
            'package_id' => $pkgFeatured->id,
            'section_slug' => PackageHomepageFeature::SECTION_SPECIAL_OFFERS,
            'position' => 1,
            'is_active' => true,
        ]);

        $resp = $this->getJson('/api/pages/home-page')->assertOk();
        $items = $resp->json('data.sections.special_offers');
        $this->assertCount(1, $items);
        $this->assertSame($pkgFeatured->id, $items[0]['id']);
        $this->assertSame('Featured Trip', $items[0]['title']);
        $this->assertNotContains($pkgNotFeatured->id, array_column($items, 'id'));
    }

    public function test_home_page_respects_section_order_and_active_flag(): void
    {
        $this->createHomePage();
        $operator = $this->makeOperator();
        $pkgA = $this->makePackage($operator, 'Trip A');
        $pkgB = $this->makePackage($operator, 'Trip B');
        $pkgC = $this->makePackage($operator, 'Trip C — inactive');

        PackageHomepageFeature::create([
            'package_id' => $pkgB->id,
            'section_slug' => 'popular_destinations',
            'position' => 1,
            'is_active' => true,
        ]);
        PackageHomepageFeature::create([
            'package_id' => $pkgA->id,
            'section_slug' => 'popular_destinations',
            'position' => 2,
            'is_active' => true,
        ]);
        PackageHomepageFeature::create([
            'package_id' => $pkgC->id,
            'section_slug' => 'popular_destinations',
            'position' => 0,
            'is_active' => false,
        ]);

        $items = $this->getJson('/api/pages/home-page')->assertOk()->json('data.sections.popular_destinations');

        $this->assertCount(2, $items);
        $this->assertSame($pkgB->id, $items[0]['id']);
        $this->assertSame($pkgA->id, $items[1]['id']);
    }

    public function test_home_page_returns_visible_operator_partners(): void
    {
        $this->createHomePage();

        $visible = Company::create([
            'name' => 'Visible Partner',
            'type' => 'operator',
            'slug' => 'visible-partner',
            'logo' => 'operators/visible.png',
            'is_partner_visible' => true,
        ]);
        Company::create([
            'name' => 'Hidden — flag off',
            'type' => 'operator',
            'slug' => 'hidden-flag',
            'logo' => 'operators/hidden.png',
            'is_partner_visible' => false,
        ]);
        Company::create([
            'name' => 'Hidden — no logo',
            'type' => 'operator',
            'slug' => 'hidden-nologo',
            'logo' => null,
            'is_partner_visible' => true,
        ]);
        Company::create([
            'name' => 'Hidden — agency type',
            'type' => 'agency',
            'slug' => 'hidden-agency',
            'logo' => 'operators/agency.png',
            'is_partner_visible' => true,
        ]);

        $partners = $this->getJson('/api/pages/home-page')->assertOk()->json('data.sections.partners');

        $this->assertCount(1, $partners);
        $this->assertSame($visible->id, $partners[0]['id']);
        $this->assertSame('operators/visible.png', $partners[0]['logo_image']);
    }

    private function createHomePage(): Page
    {
        return Page::create([
            'page_name' => 'Home Page',
            'page_slug' => 'home-page',
            'status' => 1,
            'enable_seo' => false,
            'is_bread_crumb' => false,
        ]);
    }

    private function makeOperator(): Company
    {
        return Company::create([
            'name' => 'Test Operator',
            'type' => 'operator',
            'slug' => 'test-operator-'.uniqid(),
            'is_partner_visible' => false,
        ]);
    }

    private function makePackage(Company $operator, string $title): Package
    {
        $offer = Offer::create([
            'company_id' => $operator->id,
            'type' => 'package',
            'title' => $title,
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        return Package::create([
            'offer_id' => $offer->id,
            'company_id' => $operator->id,
            'package_type' => 'fixed',
            'package_title' => $title,
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
}
