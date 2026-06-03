<?php

namespace Tests\Unit\Services\Subscriptions;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\PlanGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for PlanGateService. Two flavours of "feature":
 *   - bool flag (e.g. external_api)
 *   - limit (e.g. max_hotels) — -1 = unlimited, 0 = denied, N = capped at N
 *
 * Company with no active plan gets the defaults from PlanFeature::all().
 */
class PlanGateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_subscription_returns_defaults(): void
    {
        $company = $this->makeCompany();
        $gate = app(PlanGateService::class);

        $this->assertSame(5, $gate->limit($company->id, 'max_hotels'));
        $this->assertFalse($gate->allows($company->id, 'external_api'));
        $this->assertTrue($gate->allows($company->id, 'bulk_notifications'));
    }

    public function test_active_subscription_overlays_features(): void
    {
        $company = $this->makeCompany();
        $plan = SubscriptionPlan::query()->create([
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 49,
            'currency' => 'USD',
            'features' => [
                'max_hotels' => 50,
                'external_api' => true,
                'priority_support' => true,
            ],
            'is_active' => true,
        ]);
        CompanySubscription::query()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_period' => 'monthly',
        ]);

        $gate = app(PlanGateService::class);
        $gate->flush($company->id);

        $this->assertSame(50, $gate->limit($company->id, 'max_hotels'));
        $this->assertTrue($gate->allows($company->id, 'external_api'));
        $this->assertTrue($gate->allows($company->id, 'priority_support'));
    }

    public function test_unlimited_when_value_is_negative(): void
    {
        $company = $this->makeCompany();
        $plan = SubscriptionPlan::query()->create([
            'code' => 'unl',
            'name' => 'Unlimited',
            'monthly_price' => 199,
            'currency' => 'USD',
            'features' => ['max_hotels' => -1],
            'is_active' => true,
        ]);
        CompanySubscription::query()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_period' => 'monthly',
        ]);

        $gate = app(PlanGateService::class);
        $gate->flush($company->id);

        $this->assertNull($gate->limit($company->id, 'max_hotels'));
        $this->assertTrue($gate->canHaveOneMore($company->id, 'max_hotels', 9999));
    }

    public function test_can_have_one_more_respects_cap(): void
    {
        $company = $this->makeCompany();
        $plan = SubscriptionPlan::query()->create([
            'code' => 'basic',
            'name' => 'Basic',
            'monthly_price' => 9,
            'currency' => 'USD',
            'features' => ['max_hotels' => 3],
            'is_active' => true,
        ]);
        CompanySubscription::query()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_period' => 'monthly',
        ]);

        $gate = app(PlanGateService::class);
        $gate->flush($company->id);

        $this->assertTrue($gate->canHaveOneMore($company->id, 'max_hotels', 0));
        $this->assertTrue($gate->canHaveOneMore($company->id, 'max_hotels', 2));
        $this->assertFalse($gate->canHaveOneMore($company->id, 'max_hotels', 3));
        $this->assertFalse($gate->canHaveOneMore($company->id, 'max_hotels', 100));
    }

    public function test_cancelled_subscription_falls_back_to_defaults(): void
    {
        $company = $this->makeCompany();
        $plan = SubscriptionPlan::query()->create([
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 49,
            'currency' => 'USD',
            'features' => ['external_api' => true],
            'is_active' => true,
        ]);
        CompanySubscription::query()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'billing_period' => 'monthly',
        ]);

        $gate = app(PlanGateService::class);
        $gate->flush($company->id);

        // Cancelled → defaults
        $this->assertFalse($gate->allows($company->id, 'external_api'));
    }

    public function test_legacy_flat_list_features_treated_as_bool_flags(): void
    {
        $company = $this->makeCompany();
        $plan = SubscriptionPlan::query()->create([
            'code' => 'legacy',
            'name' => 'Legacy plan',
            'monthly_price' => 19,
            'currency' => 'USD',
            'features' => ['external_api', 'priority_support'],
            'is_active' => true,
        ]);
        CompanySubscription::query()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_period' => 'monthly',
        ]);

        $gate = app(PlanGateService::class);
        $gate->flush($company->id);

        $this->assertTrue($gate->allows($company->id, 'external_api'));
        $this->assertTrue($gate->allows($company->id, 'priority_support'));
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'PlanGate '.str()->uuid(),
            'type' => 'operator',
        ]);
    }
}
