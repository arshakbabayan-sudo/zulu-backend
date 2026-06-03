<?php

namespace Tests\Feature\Insurance;

use App\Models\Company;
use App\Models\InsuranceProduct;
use App\Models\User;
use App\Services\Insurance\InsurancePolicyService;
use App\Services\Insurance\InsurancePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InsurancePricingAndPolicyTest extends TestCase
{
    use RefreshDatabase;

    private InsurancePricingService $pricing;

    private InsurancePolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = app(InsurancePricingService::class);
        $this->policy = app(InsurancePolicyService::class);
    }

    public function test_quote_applies_age_tier_and_duration(): void
    {
        $product = $this->makeProduct(); // base 10, age tier 18-65=1.0, 66+=2.0, durations [7,14,30]
        // 2 travelers (ages 30 and 70), 14 days
        // 30: 10 * 1.0 * (14/7=2) = 20
        // 70: 10 * 2.0 * 2 = 40
        // total = 60
        $quote = $this->pricing->quote($product, [['age' => 30], ['age' => 70]], 14);

        $this->assertSame(60.0, $quote['premium']);
        $this->assertCount(2, $quote['breakdown']);
        $this->assertSame(20.0, $quote['breakdown'][0]['line_total']);
        $this->assertSame(40.0, $quote['breakdown'][1]['line_total']);
    }

    public function test_quote_handles_unknown_age_with_default_multiplier(): void
    {
        $product = $this->makeProduct();
        $quote = $this->pricing->quote($product, [['age' => 5]], 7); // 5 not in any tier
        // multiplier 1.0 default, duration 7/7=1
        // 10 * 1.0 * 1 = 10
        $this->assertSame(10.0, $quote['premium']);
    }

    public function test_quote_rejects_empty_travelers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->quote($this->makeProduct(), [], 7);
    }

    public function test_issue_creates_policy_with_snapshot(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create();

        $issued = $this->policy->issue(
            $product,
            [['age' => 30, 'name' => 'Jane Doe', 'passport' => 'AM999']],
            '2026-06-01',
            7,
            user: $user,
        );

        $this->assertSame('active', $issued->status);
        $this->assertStringStartsWith('POL-', $issued->policy_number);
        $this->assertSame('2026-06-01', $issued->coverage_start_date->toDateString());
        $this->assertSame('2026-06-07', $issued->coverage_end_date->toDateString());
        $this->assertSame(7, $issued->coverage_days);
        $this->assertSame(10.0, (float) $issued->premium_paid);
        $this->assertSame('Jane Doe', $issued->insured_persons[0]['name']);
        $this->assertSame(30, $issued->insured_persons[0]['age_at_issue']);
        $this->assertSame($product->underwriter_name, $issued->product_snapshot['underwriter_name']);
        $this->assertTrue($issued->isActive());
    }

    public function test_issue_rejects_inactive_product(): void
    {
        $product = $this->makeProduct();
        $product->status = 'inactive';
        $product->save();

        $this->expectException(InvalidArgumentException::class);
        $this->policy->issue($product, [['age' => 30]], '2026-06-01', 7);
    }

    public function test_cancel_active_policy_records_reason(): void
    {
        $product = $this->makeProduct();
        $issued = $this->policy->issue($product, [['age' => 30]], '2026-06-01', 7);

        $cancelled = $this->policy->cancel($issued, 'Trip cancelled by customer');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Trip cancelled by customer', $cancelled->cancellation_reason);
    }

    private function makeProduct(): InsuranceProduct
    {
        $company = Company::query()->create([
            'name' => 'InsCo '.str()->random(4),
            'type' => 'operator',
        ]);

        return InsuranceProduct::query()->create([
            'company_id' => $company->id,
            'underwriter_name' => 'Acme Insurance',
            'product_name' => 'Travel Plus',
            'coverage_territory' => 'worldwide',
            'coverage_details' => ['medical' => 50000, 'baggage' => 1000],
            'age_tiers' => [
                ['min_age' => 18, 'max_age' => 65, 'multiplier' => 1.0],
                ['min_age' => 66, 'max_age' => 99, 'multiplier' => 2.0],
            ],
            'base_premium' => 10,
            'currency' => 'USD',
            'duration_options' => [7, 14, 30],
            'pre_existing_covered' => false,
            'status' => 'active',
        ]);
    }
}
