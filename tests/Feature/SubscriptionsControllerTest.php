<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.11 feature tests — subscription plans + company subscriptions.
 *
 * Routes:
 *   GET   /api/subscription-plans
 *   POST  /api/subscription-plans               (super admin only)
 *   PATCH /api/subscription-plans/{id}          (super admin only)
 *   GET   /api/company-subscriptions            (super admin only)
 *   PATCH /api/company-subscriptions/{companyId}(super admin only)
 *
 * The write surface and the company-subscription view are super-admin-only.
 * Regular auth users can only read the active plans catalog.
 */
class SubscriptionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/subscription-plans')->assertStatus(401);
        $this->postJson('/api/subscription-plans', [])->assertStatus(401);
    }

    public function test_list_plans_returns_active_only_for_regular_user(): void
    {
        SubscriptionPlan::query()->create([
            'code' => 'free',
            'name' => 'Free',
            'monthly_price' => 0,
            'currency' => 'USD',
            'is_active' => true,
            'display_order' => 1,
        ]);
        SubscriptionPlan::query()->create([
            'code' => 'legacy',
            'name' => 'Legacy (off)',
            'monthly_price' => 9,
            'currency' => 'USD',
            'is_active' => false,
            'display_order' => 99,
        ]);

        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/subscription-plans');
        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('free', $codes);
        $this->assertNotContains('legacy', $codes);
    }

    public function test_store_plan_requires_super_admin(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/subscription-plans', [
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 49,
        ])->assertStatus(403);
    }

    public function test_super_admin_can_store_a_plan_and_currency_is_normalized(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/subscription-plans', [
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 49,
            'annual_price' => 499,
            'currency' => 'usd',
            'features' => ['priority_support', 'analytics_v2'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'pro');
        $response->assertJsonPath('data.currency', 'USD');
        $response->assertJsonPath('data.features.0', 'priority_support');
    }

    public function test_store_plan_rejects_duplicate_code(): void
    {
        SubscriptionPlan::query()->create([
            'code' => 'taken',
            'name' => 'Taken',
            'monthly_price' => 1,
            'currency' => 'USD',
        ]);

        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson('/api/subscription-plans', [
            'code' => 'taken',
            'name' => 'Duplicate',
            'monthly_price' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_update_plan_updates_existing_row(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 49,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->patchJson("/api/subscription-plans/{$plan->id}", [
            'name' => 'Pro+',
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Pro+');
        $response->assertJsonPath('data.is_active', false);
    }

    public function test_assign_company_subscription_upserts_in_place(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 49,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $company = $this->makeCompany();

        Sanctum::actingAs($this->makeSuperAdmin());

        // First assignment — insert
        $this->patchJson("/api/company-subscriptions/{$company->id}", [
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_period' => 'monthly',
        ])->assertOk()->assertJsonPath('data.plan.id', $plan->id);

        // Second assignment same company — update in place
        $newPlan = SubscriptionPlan::query()->create([
            'code' => 'business',
            'name' => 'Business',
            'monthly_price' => 199,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->patchJson("/api/company-subscriptions/{$company->id}", [
            'plan_id' => $newPlan->id,
            'status' => 'active',
            'billing_period' => 'annual',
        ])->assertOk()->assertJsonPath('data.plan.id', $newPlan->id);

        $this->assertEquals(1, CompanySubscription::query()->where('company_id', $company->id)->count());
    }

    public function test_list_company_subscriptions_requires_super_admin(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/company-subscriptions')->assertStatus(403);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.11 Test',
            'email' => 'p711-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.11 '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function makeSuperAdmin(): User
    {
        // The SubscriptionsController reads $user->is_super_admin directly
        // (no DB column — it's a dynamic attribute populated by RBAC code).
        // For tests we set the attribute in-memory on a Sanctum-actingAs
        // user and skip persisting it, matching what the controller sees at
        // request time. Role attachment to a platform company stays so the
        // user looks like a real super-admin to any code that prefers
        // AdminAccessService::isSuperAdmin().
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'super_admin']);
        $platform = $this->makeCompany();
        $user->companies()->attach($platform->id, ['role_id' => $role->id]);
        $user->is_super_admin = true;

        return $user;
    }
}
