<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderPricingSnapshot;
use App\Models\Payment;
use App\Models\SellerFxRate;
use App\Models\User;
use App\Services\Payments\ChargeAmountResolver;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * B3 — seller FX charge conversion + frozen snapshot, SAFE-BY-DEFAULT.
 *
 * Asserts the customer-charge path: a non-AMD order is charged the AMD amount
 * (order.total × seller-effective-rate) computed SERVER-SIDE, with the rate
 * frozen into order_pricing_snapshots.snapshot_json.fx — and that nothing
 * changes when no FX setting/base rate resolves or the order is already AMD.
 *
 * The PaymentGatewayService is mocked (isConfigured + createPaymentIntent) so
 * we exercise the controller's server-side amount/currency + snapshot logic
 * without a live gateway; assertions read the persisted Payment + snapshot,
 * which are written BEFORE the gateway call.
 */
class SellerFxChargeConversionTest extends TestCase
{
    use RefreshDatabase;

    private function mockGatewayConfigured(): void
    {
        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('createPaymentIntent')->andReturn([
                'success' => true,
                'client_secret' => 'cs_test_fx',
                'gateway_reference' => 'pi_test_fx',
            ]);
        });
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function operatorCompany(): Company
    {
        return Company::query()->create(['name' => 'Op Co '.uniqid(), 'type' => 'operator']);
    }

    private function makeOrder(User $user, Company $operator, string $currency, float $total, ?int $agentCompanyId = null): Order
    {
        return Order::query()->create([
            'order_number' => 'FX-'.str()->uuid(),
            'user_id' => $user->id,
            'company_id' => $operator->id,
            'agent_company_id' => $agentCompanyId,
            'status' => 'pending_payment',
            'currency' => $currency,
            'total' => $total,
        ]);
    }

    private function seedCba(string $source, string $target, string $rate): void
    {
        ExchangeRate::query()->create([
            'source_currency' => $source,
            'target_currency' => $target,
            'rate' => $rate,
            'source' => ExchangeRate::SOURCE_CBA,
            'fetched_at' => now(),
            'is_active' => true,
        ]);
    }

    private function seedOperatorSetting(Company $operator, string $margin): SellerFxRate
    {
        return SellerFxRate::query()->create([
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $operator->id,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => $margin,
            'is_active' => true,
        ]);
    }

    // (a) non-AMD order + operator seller setting + seeded CBA rate →
    //     Payment charged in AMD at orderTotal × (rate + margin), snapshot frozen.
    public function test_marketplace_non_amd_order_is_charged_amd_at_seller_rate_with_frozen_snapshot(): void
    {
        $this->mockGatewayConfigured();

        $user = $this->customer();
        $operator = $this->operatorCompany();
        $order = $this->makeOrder($user, $operator, 'USD', 100.0);

        $this->seedCba('USD', 'AMD', '400.000000');
        $setting = $this->seedOperatorSetting($operator, '2'); // effective 402

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/marketplace/bookings/{$order->id}/payment-intent");

        $response->assertOk();
        // 100 * (400 + 2) = 40200.00 AMD; response surfaces the charged values.
        $this->assertEqualsWithDelta(40200.0, $response->json('data.amount'), 0.001);
        $this->assertSame('amd', $response->json('data.currency'));

        $paymentId = $response->json('data.payment_id');
        $payment = Payment::query()->findOrFail($paymentId);
        $this->assertSame(40200.0, (float) $payment->amount);
        $this->assertSame('AMD', strtoupper((string) $payment->currency));

        // Order itself is untouched (native).
        $this->assertSame('USD', $order->fresh()->currency);
        $this->assertSame('100.00', (string) $order->fresh()->total);

        // Frozen FX snapshot written once, with the resolved rate.
        $snap = OrderPricingSnapshot::query()
            ->where('order_id', $order->id)
            ->get()
            ->first(fn (OrderPricingSnapshot $r): bool => ($r->snapshot_json['fx']['kind'] ?? null) === ChargeAmountResolver::FX_KIND);
        $this->assertNotNull($snap);
        $fx = $snap->snapshot_json['fx'];
        $this->assertSame('402.0000', $fx['rate']);
        $this->assertSame('USD', $fx['source_currency']);
        $this->assertSame('AMD', $fx['target_currency']);
        $this->assertSame(100.0, (float) $fx['amount_source']);
        $this->assertSame(40200.0, (float) $fx['amount_target']);
        $this->assertSame($setting->id, $fx['setting_id']);
    }

    public function test_package_order_non_amd_is_charged_amd_at_seller_rate(): void
    {
        $this->mockGatewayConfigured();

        $user = $this->customer();
        $operator = $this->operatorCompany();
        // PackageOrderController::createPaymentIntent resolves the order via
        // findForUserUuid → must carry the package_order legacy_origin marker.
        $order = Order::query()->create([
            'order_number' => 'PKG-FX-'.str()->uuid(),
            'user_id' => $user->id,
            'company_id' => $operator->id,
            'status' => 'pending_payment',
            'currency' => 'EUR',
            'total' => 50.0,
            'metadata' => ['legacy_origin' => 'package_order'],
        ]);

        $this->seedCba('EUR', 'AMD', '430.000000');
        $this->seedOperatorSetting($operator, '5'); // effective 435

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/package-orders/{$order->id}/payment-intent");

        $response->assertOk();
        // 50 * (430 + 5) = 21750.00 AMD
        $this->assertEqualsWithDelta(21750.0, $response->json('data.amount'), 0.001);
        $this->assertSame('amd', $response->json('data.currency'));

        $payment = Payment::query()->findOrFail($response->json('data.payment_id'));
        $this->assertSame(21750.0, (float) $payment->amount);
        $this->assertSame('AMD', strtoupper((string) $payment->currency));
    }

    // (b) SAFE-BY-DEFAULT: no FX setting at any scope → charge unchanged, no snapshot.
    public function test_safe_by_default_no_setting_leaves_charge_native_and_writes_no_snapshot(): void
    {
        $this->mockGatewayConfigured();

        $user = $this->customer();
        $operator = $this->operatorCompany();
        $order = $this->makeOrder($user, $operator, 'USD', 100.0);

        // CBA rate exists but NO seller_fx_rates setting at any scope.
        $this->seedCba('USD', 'AMD', '400.000000');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/marketplace/bookings/{$order->id}/payment-intent");

        $response->assertOk();
        $this->assertEqualsWithDelta(100.0, $response->json('data.amount'), 0.001);
        $this->assertSame('usd', $response->json('data.currency'));

        $payment = Payment::query()->findOrFail($response->json('data.payment_id'));
        $this->assertSame(100.0, (float) $payment->amount);
        $this->assertSame('USD', strtoupper((string) $payment->currency));

        $this->assertSame(0, OrderPricingSnapshot::query()->where('order_id', $order->id)->count());
    }

    // (b2) SAFE-BY-DEFAULT: setting present but NO base rate (no CBA row) → null → native.
    public function test_safe_by_default_setting_without_base_rate_leaves_charge_native(): void
    {
        $this->mockGatewayConfigured();

        $user = $this->customer();
        $operator = $this->operatorCompany();
        $order = $this->makeOrder($user, $operator, 'USD', 100.0);

        // Operator setting exists but NO exchange_rates CBA row for USD→AMD.
        $this->seedOperatorSetting($operator, '2');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/marketplace/bookings/{$order->id}/payment-intent");

        $response->assertOk();
        $this->assertEqualsWithDelta(100.0, $response->json('data.amount'), 0.001);
        $this->assertSame('usd', $response->json('data.currency'));

        $payment = Payment::query()->findOrFail($response->json('data.payment_id'));
        $this->assertSame('USD', strtoupper((string) $payment->currency));
        $this->assertSame(0, OrderPricingSnapshot::query()->where('order_id', $order->id)->count());
    }

    // (c) already-AMD order → unchanged, no double conversion, no snapshot.
    public function test_already_amd_order_is_unchanged(): void
    {
        $this->mockGatewayConfigured();

        $user = $this->customer();
        $operator = $this->operatorCompany();
        $order = $this->makeOrder($user, $operator, 'AMD', 40000.0);

        // Even a configured AMD→AMD setting + rate must not double-convert.
        $this->seedOperatorSetting($operator, '50');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/marketplace/bookings/{$order->id}/payment-intent");

        $response->assertOk();
        $this->assertEqualsWithDelta(40000.0, $response->json('data.amount'), 0.001);
        $this->assertSame('amd', $response->json('data.currency'));

        $payment = Payment::query()->findOrFail($response->json('data.payment_id'));
        $this->assertSame(40000.0, (float) $payment->amount);
        $this->assertSame('AMD', strtoupper((string) $payment->currency));
        $this->assertSame(0, OrderPricingSnapshot::query()->where('order_id', $order->id)->count());
    }

    // (d) frozen rate is used even if the rate row later changes (idempotent reuse).
    public function test_frozen_rate_survives_a_later_rate_change(): void
    {
        $this->mockGatewayConfigured();

        $user = $this->customer();
        $operator = $this->operatorCompany();
        $order = $this->makeOrder($user, $operator, 'USD', 100.0);

        $this->seedCba('USD', 'AMD', '400.000000');
        $this->seedOperatorSetting($operator, '2'); // effective 402

        Sanctum::actingAs($user);
        $first = $this->postJson("/api/marketplace/bookings/{$order->id}/payment-intent");
        $first->assertOk();
        $this->assertEqualsWithDelta(40200.0, $first->json('data.amount'), 0.001);
        $firstPaymentId = $first->json('data.payment_id');

        // Rate moves AFTER the intent was created: deactivate old CBA + add a new one.
        ExchangeRate::query()->where('source_currency', 'USD')->where('target_currency', 'AMD')->update(['is_active' => false]);
        $this->seedCba('USD', 'AMD', '500.000000'); // would be 100*(500+2)=50200 if re-priced

        // Idempotent re-call reuses the same pending Payment and MUST keep the
        // originally frozen 40200, not re-price to 50200.
        $second = $this->postJson("/api/marketplace/bookings/{$order->id}/payment-intent");
        $second->assertOk();
        $this->assertEqualsWithDelta(40200.0, $second->json('data.amount'), 0.001);
        $this->assertSame($firstPaymentId, $second->json('data.payment_id'));

        $payment = Payment::query()->findOrFail($firstPaymentId);
        $this->assertSame(40200.0, (float) $payment->amount);

        // Still exactly ONE frozen FX snapshot (not rewritten).
        $count = OrderPricingSnapshot::query()
            ->where('order_id', $order->id)
            ->get()
            ->filter(fn (OrderPricingSnapshot $r): bool => ($r->snapshot_json['fx']['kind'] ?? null) === ChargeAmountResolver::FX_KIND)
            ->count();
        $this->assertSame(1, $count);
    }

    // Scope precedence end-to-end: agent margin overrides operator margin on the charge.
    public function test_agent_scope_overrides_operator_scope_on_charge(): void
    {
        $this->mockGatewayConfigured();

        $user = $this->customer();
        $operator = $this->operatorCompany();
        $agent = Company::query()->create(['name' => 'Agent Co '.uniqid(), 'type' => 'agency']);
        $order = $this->makeOrder($user, $operator, 'USD', 100.0, $agent->id);

        $this->seedCba('USD', 'AMD', '400.000000');
        $this->seedOperatorSetting($operator, '2'); // operator effective 402
        SellerFxRate::query()->create([
            'scope' => SellerFxRate::SCOPE_AGENT,
            'company_id' => $agent->id,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '10', // agent effective 410
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/marketplace/bookings/{$order->id}/payment-intent");

        $response->assertOk();
        // Agent wins: 100 * (400 + 10) = 41000.00
        $this->assertEqualsWithDelta(41000.0, $response->json('data.amount'), 0.001);
    }
}
