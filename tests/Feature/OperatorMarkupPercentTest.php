<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Api\CatalogOfferDetailResource;
use App\Models\Company;
use App\Models\Offer;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\Pricing\PricingResolver;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-operator markup: super-admin sets two tiers (agent netto / customer
 * brutto); the customer-site DISPLAY and the booking CHARGE both read the
 * SAME resolution so "shown == charged" for an operator with no pricing_rule.
 */
class OperatorMarkupPercentTest extends TestCase
{
    use RefreshDatabase;

    private function makeOperator(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Op '.uniqid(),
            'type' => 'operator',
        ], $overrides));
    }

    private function makeOffer(Company $operator, float $price = 100.0, string $currency = 'USD'): Offer
    {
        return Offer::create([
            'company_id' => $operator->id,
            'type' => 'hotel',
            'title' => 'Hotel offer',
            'price' => $price,
            'currency' => $currency,
            'status' => 'active',
        ]);
    }

    private function displayPrice(Offer $offer): float
    {
        $resource = new CatalogOfferDetailResource($offer->fresh());
        $array = $resource->toArray(Request::create('/api/catalog/offers/'.$offer->id, 'GET'));

        return (float) $array['price'];
    }

    /** (a) customer_markup_percent=20 → display 'price' AND client charge BOTH = price*1.20, and EQUAL. */
    public function test_customer_markup_display_equals_client_charge(): void
    {
        $operator = $this->makeOperator(['customer_markup_percent' => 20]);
        $offer = $this->makeOffer($operator, 100.0);

        $display = $this->displayPrice($offer);

        $charge = app(PricingResolver::class)->resolve($offer->id, 1, ['buyer_type' => 'client']);

        $this->assertSame(120.0, $display, 'display price should be 100 * 1.20');
        $this->assertSame(120.0, $charge->customerPrice, 'client charge should be 100 * 1.20');
        $this->assertSame($display, $charge->customerPrice, 'display must EQUAL charge');
        $this->assertSame('operator_markup_percent', $charge->snapshotPayload['engine']);
    }

    /** (b) seller_b2b buyer with agent_markup_percent=10 → charge = price*1.10. */
    public function test_agent_markup_applies_to_seller_b2b_charge(): void
    {
        $operator = $this->makeOperator([
            'agent_markup_percent' => 10,
            'customer_markup_percent' => 20,
        ]);
        $offer = $this->makeOffer($operator, 100.0);

        $charge = app(PricingResolver::class)->resolve($offer->id, 1, ['buyer_type' => 'seller_b2b']);

        $this->assertSame(110.0, $charge->customerPrice, 'agent charge should be 100 * 1.10');
        $this->assertSame('operator_markup_percent', $charge->snapshotPayload['engine']);
    }

    /** (c) both columns NULL → global 15% unchanged (backwards-compatible). */
    public function test_null_columns_fall_back_to_global_fifteen_percent(): void
    {
        $operator = $this->makeOperator(); // both NULL
        $offer = $this->makeOffer($operator, 100.0);

        $display = $this->displayPrice($offer);
        $charge = app(PricingResolver::class)->resolve($offer->id, 1, ['buyer_type' => 'client']);

        $this->assertSame(115.0, $display, 'NULL operator → global 15% display');
        $this->assertSame(115.0, $charge->customerPrice, 'NULL operator → global 15% charge');
        $this->assertSame($display, $charge->customerPrice);
    }

    /** (d) a matching pricing_rule still wins over the per-operator column. */
    public function test_pricing_rule_wins_over_per_operator_column(): void
    {
        $operator = $this->makeOperator(['customer_markup_percent' => 20]);
        $offer = $this->makeOffer($operator, 100.0, 'USD');

        // Operator-scoped rule with a DIFFERENT markup (25%).
        PricingRule::create([
            'scope_type' => PricingRule::SCOPE_OPERATOR,
            'operator_id' => $operator->id,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 25,
            'currency' => 'USD',
            'effective_from' => now()->subDay(),
            'priority' => 100,
            'is_active' => true,
        ]);

        $charge = app(PricingResolver::class)->resolve($offer->id, 1, ['buyer_type' => 'client']);

        // Rule wins → 100 * 1.25 = 125, NOT the column's 120.
        $this->assertSame(125.0, $charge->customerPrice);
        $this->assertSame('phase_1_pricing_rules_v1', $charge->snapshotPayload['engine']);
    }

    /** (e) platform-admin company update saves the two percents and validates bounds. */
    public function test_platform_admin_update_saves_and_validates_markups(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $company = Company::create([
            'name' => 'Markup Co',
            'type' => 'operator',
            'slug' => 'markup-co',
        ]);

        // Valid save.
        $resp = $this->patchJson("/api/platform-admin/companies/{$company->id}/profile", [
            'agent_markup_percent' => 12.5,
            'customer_markup_percent' => 22,
        ])->assertOk();

        // Compare via float cast — JSON serializes a whole-number decimal as
        // an int (22), which trips assertJsonPath's strict int/float check.
        $this->assertSame(12.5, (float) $resp->json('data.agent_markup_percent'));
        $this->assertSame(22.0, (float) $resp->json('data.customer_markup_percent'));

        $company->refresh();
        $this->assertSame('12.50', (string) $company->agent_markup_percent);
        $this->assertSame('22.00', (string) $company->customer_markup_percent);

        // Reject > 100.
        $this->patchJson("/api/platform-admin/companies/{$company->id}/profile", [
            'customer_markup_percent' => 150,
        ])->assertStatus(422);

        // Reject negative.
        $this->patchJson("/api/platform-admin/companies/{$company->id}/profile", [
            'agent_markup_percent' => -5,
        ])->assertStatus(422);
    }
}
