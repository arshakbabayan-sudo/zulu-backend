<?php

namespace Tests\Feature\Insurance;

use App\Models\Company;
use App\Models\InsuranceProduct;
use App\Models\User;
use App\Services\Insurance\InsurancePolicyService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsuranceApiTest extends TestCase
{
    use RefreshDatabase;

    // === Customer ===

    public function test_customer_lists_active_products_only(): void
    {
        $user = User::factory()->create();
        $active = $this->makeProduct(['status' => 'active']);
        $inactive = $this->makeProduct(['status' => 'inactive']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/customer/insurance/products');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_customer_quote_returns_premium(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/customer/insurance/quote', [
            'product_id' => $product->id,
            'travelers' => [['age' => 30]],
            'coverage_days' => 7,
        ]);

        $response->assertOk();
        $this->assertEquals(10, $response->json('data.premium')); // base 10 × 1.0 × (7/7)
    }

    public function test_customer_purchase_creates_active_policy(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/customer/insurance/purchase', [
            'product_id' => $product->id,
            'travelers' => [['age' => 30, 'name' => 'Foo Bar']],
            'coverage_start_date' => '2026-06-01',
            'coverage_days' => 7,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.user_id', $user->id);
    }

    public function test_customer_my_policies_only_returns_own(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $product = $this->makeProduct();

        $aliceP = app(InsurancePolicyService::class)->issue($product, [['age' => 30]], '2026-06-01', 7, user: $alice);
        $bobP = app(InsurancePolicyService::class)->issue($product, [['age' => 40]], '2026-06-01', 7, user: $bob);

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/customer/insurance/policies');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($aliceP->id, $response->json('data.0.id'));
    }

    public function test_customer_show_policy_404_for_other_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $product = $this->makeProduct();

        $bobPolicy = app(InsurancePolicyService::class)->issue($product, [['age' => 30]], '2026-06-01', 7, user: $bob);

        Sanctum::actingAs($alice);
        $this->getJson('/api/customer/insurance/policies/'.$bobPolicy->id)->assertStatus(404);
    }

    // === Admin ===

    public function test_admin_index_products_requires_admin(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->getJson('/api/platform-admin/insurance/products')->assertStatus(403);
    }

    public function test_admin_store_product(): void
    {
        $admin = $this->createPlatformAdmin();
        $company = Company::query()->create(['name' => 'Co', 'type' => 'operator']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/insurance/products', [
            'company_id' => $company->id,
            'underwriter_name' => 'Acme',
            'product_name' => 'Standard',
            'coverage_territory' => 'worldwide',
            'coverage_details' => ['medical' => 50000],
            'age_tiers' => [['min_age' => 18, 'max_age' => 65, 'multiplier' => 1.0]],
            'base_premium' => 15,
            'currency' => 'USD',
            'duration_options' => [7, 14],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.product_name', 'Standard');
    }

    public function test_admin_cancel_policy_requires_reason(): void
    {
        $admin = $this->createPlatformAdmin();
        $product = $this->makeProduct();
        $policy = app(InsurancePolicyService::class)->issue($product, [['age' => 30]], '2026-06-01', 7);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/insurance/policies/'.$policy->id.'/cancel', []);
        $response->assertStatus(422);
    }

    public function test_admin_cancel_policy_with_reason(): void
    {
        $admin = $this->createPlatformAdmin();
        $product = $this->makeProduct();
        $policy = app(InsurancePolicyService::class)->issue($product, [['age' => 30]], '2026-06-01', 7);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/insurance/policies/'.$policy->id.'/cancel', [
            'reason' => 'Underwriter recall',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProduct(array $overrides = []): InsuranceProduct
    {
        $company = Company::query()->create(['name' => 'Co '.str()->random(4), 'type' => 'operator']);

        return InsuranceProduct::query()->create(array_merge([
            'company_id' => $company->id,
            'underwriter_name' => 'Acme',
            'product_name' => 'Travel Plus',
            'coverage_territory' => 'worldwide',
            'coverage_details' => ['medical' => 50000],
            'age_tiers' => [['min_age' => 18, 'max_age' => 99, 'multiplier' => 1.0]],
            'base_premium' => 10,
            'currency' => 'USD',
            'duration_options' => [7, 14, 30],
            'status' => 'active',
        ], $overrides));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}
