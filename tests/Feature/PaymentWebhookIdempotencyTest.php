<?php

namespace Tests\Feature;

use App\Mail\PaymentReceivedMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\User;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Backend audit C3 — the Stripe (and other) webhook MUST be idempotent and
 * concurrency-safe: the SAME gateway event delivered twice (Stripe retries
 * aggressively) must be processed AT MOST ONCE — one PaymentReceived, one
 * invoice/order cascade, one customer + one seller loyalty accrual, one email —
 * while a brand-new event still fully processes exactly as before.
 *
 * These tests drive the real PaymentWebhookController through the route. We
 * mock PaymentGatewayService so usingDriver()->constructWebhookEvent() returns
 * a crafted event (bypassing the Stripe SDK signature check), but everything
 * downstream — processEvent, the unique idempotency index, markPaid, the
 * PaymentReceived cascade, loyalty — runs for real against the DB.
 */
class PaymentWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * (a) The SAME Stripe event delivered TWICE → paid once, exactly one
     * idempotency log row, one invoice/order cascade, one customer + one seller
     * loyalty accrual, one payment-received email. Second delivery is a no-op.
     */
    public function test_duplicate_stripe_event_is_processed_at_most_once(): void
    {
        Mail::fake();

        [$payment, $order, $customer, $sellerCompany] = $this->createPaidableChain();

        $eventId = 'evt_dup_'.str()->uuid();
        $this->fakeGatewayEvent(
            driver: 'stripe',
            eventType: 'payment_intent.succeeded',
            reference: $payment->reference_code,
            eventId: $eventId,
        );

        // First delivery — fully processes.
        $this->postJson('/api/payments/webhook', ['fake' => 'payload'])
            ->assertOk()
            ->assertJson(['received' => true]);

        // Second delivery — duplicate of the SAME event id. Must early-return.
        $this->postJson('/api/payments/webhook', ['fake' => 'payload'])
            ->assertOk()
            ->assertJson(['received' => true]);

        // Payment paid exactly once.
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        // Exactly ONE idempotency log row carrying this gateway_event_id.
        $this->assertSame(
            1,
            PaymentLog::query()
                ->where('gateway', 'stripe')
                ->where('gateway_event_id', $eventId)
                ->count(),
            'duplicate event must yield exactly one idempotency log row'
        );

        // Invoice + Order cascaded to paid (fired once; listener is itself idempotent).
        $this->assertSame(Invoice::STATUS_PAID, $payment->fresh()->invoice->status);
        $this->assertSame('paid', $order->fresh()->status);

        // Loyalty: exactly ONE customer earn + ONE seller earn for this order.
        $customerAccount = LoyaltyAccount::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($customerAccount);
        $this->assertSame(1, $this->orderEarnCount($customerAccount->id, $order->id));

        $sellerAccount = LoyaltyAccount::query()
            ->where('company_id', $sellerCompany->id)
            ->where('account_type', LoyaltyAccount::TYPE_SELLER)
            ->first();
        $this->assertNotNull($sellerAccount);
        $this->assertSame(1, $this->orderEarnCount($sellerAccount->id, $order->id));

        // Exactly ONE payment-received email queued.
        Mail::assertQueued(PaymentReceivedMail::class, 1);
    }

    /**
     * (b) A first-time event still fully processes — paid, invoice paid, order
     * paid, points credited, email queued. Regression guard that the fix did
     * not break the happy path.
     */
    public function test_first_time_event_fully_processes(): void
    {
        Mail::fake();

        [$payment, $order, $customer, $sellerCompany] = $this->createPaidableChain();

        $this->fakeGatewayEvent(
            driver: 'stripe',
            eventType: 'payment_intent.succeeded',
            reference: $payment->reference_code,
            eventId: 'evt_first_'.str()->uuid(),
        );

        $this->postJson('/api/payments/webhook', ['fake' => 'payload'])->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame(Invoice::STATUS_PAID, $payment->fresh()->invoice->status);
        $this->assertSame('paid', $order->fresh()->status);

        $customerAccount = LoyaltyAccount::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($customerAccount, 'customer loyalty account auto-created on first paid payment');
        $this->assertGreaterThan(0, $customerAccount->points_balance);

        Mail::assertQueued(PaymentReceivedMail::class, 1);
    }

    /**
     * (c) Distinct events on the SAME payment are NOT both swallowed — a
     * succeeded then a legitimate later refund.succeeded must each log.
     */
    public function test_distinct_events_on_same_payment_are_not_deduped(): void
    {
        Mail::fake();

        [$payment] = $this->createPaidableChain();

        // succeeded (evt_A)
        $this->fakeGatewayEvent('stripe', 'payment_intent.succeeded', $payment->reference_code, 'evt_A_'.str()->uuid());
        $this->postJson('/api/payments/webhook', ['fake' => 'payload'])->assertOk();

        // refund (evt_B — different event id)
        $this->fakeGatewayEvent('stripe', 'charge.refunded', $payment->reference_code, 'evt_B_'.str()->uuid());
        $this->postJson('/api/payments/webhook', ['fake' => 'payload'])->assertOk();

        $this->assertSame(
            1,
            PaymentLog::query()->where('event_type', 'webhook.payment_intent.succeeded')->count()
        );
        $this->assertSame(
            1,
            PaymentLog::query()->where('event_type', 'webhook.refund.succeeded')->count()
        );
    }

    /**
     * (d) Unknown reference / 'other' event still returns 200 and no-ops.
     */
    public function test_unknown_reference_returns_200_and_no_ops(): void
    {
        $this->fakeGatewayEvent('stripe', 'payment_intent.succeeded', 'pi_does_not_exist', 'evt_unknown');

        $this->postJson('/api/payments/webhook', ['fake' => 'payload'])
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame(0, PaymentLog::query()->where('gateway_event_id', 'evt_unknown')->count());
    }

    /**
     * markPaid no-op guard: calling markPaid twice directly fires side-effects
     * exactly once (no double loyalty, no double cascade).
     */
    public function test_mark_paid_is_a_no_op_when_already_paid(): void
    {
        Mail::fake();

        [$payment, $order, $customer] = $this->createPaidableChain();

        $service = app(\App\Services\Payments\PaymentService::class);
        $service->markPaid($payment);
        $service->markPaid($payment->fresh());

        $customerAccount = LoyaltyAccount::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($customerAccount);
        $this->assertSame(1, $this->orderEarnCount($customerAccount->id, $order->id));
        Mail::assertQueued(PaymentReceivedMail::class, 1);
    }

    /**
     * Per-gateway key extraction: Idram surfaces EDP_TRANS_ID as the event id;
     * the fallback path persists the composite (reference:normalized) token.
     */
    public function test_idram_persists_trans_id_as_idempotency_key(): void
    {
        config()->set('payment.idram.receiver', '12345');
        config()->set('payment.idram.secret', 'shared-secret');

        [$payment] = $this->createPaidableChain();

        $this->fakeGatewayEvent('idram', 'payment.succeeded', $payment->reference_code, 'EDP-TXN-777');

        $this->postJson('/api/payments/webhook/idram', ['fake' => 'payload'])->assertOk();

        $this->assertSame(
            1,
            PaymentLog::query()->where('gateway', 'idram')->where('gateway_event_id', 'EDP-TXN-777')->count()
        );
    }

    public function test_fallback_composite_key_used_when_no_event_id(): void
    {
        [$payment] = $this->createPaidableChain();

        // No event id surfaced — controller must fall back to "reference:normalized".
        $this->fakeGatewayEvent('stripe', 'payment_intent.succeeded', $payment->reference_code, '');

        $this->postJson('/api/payments/webhook', ['fake' => 'payload'])->assertOk();

        $expectedKey = $payment->reference_code.':payment.succeeded';
        $this->assertSame(
            1,
            PaymentLog::query()->where('gateway_event_id', $expectedKey)->count()
        );
    }

    /**
     * (c-migration) Migration safety: NULL-key rows (mimicking intent.created /
     * intent.retrieved) coexist with the partial unique index — new NULL-key
     * inserts still succeed and never collide, while a duplicate non-null key
     * is rejected.
     */
    public function test_partial_unique_index_ignores_null_keys_but_blocks_duplicate_non_null(): void
    {
        // Many NULL-key rows (the index must ignore these).
        for ($i = 0; $i < 3; $i++) {
            PaymentLog::query()->create([
                'event_type' => 'intent.created',
                'gateway' => 'stripe',
                'gateway_reference' => 'pi_'.$i,
                // gateway_event_id deliberately omitted → NULL
            ]);
        }
        $this->assertSame(3, PaymentLog::query()->whereNull('gateway_event_id')->count());

        // First non-null key inserts fine.
        PaymentLog::query()->create([
            'event_type' => 'webhook.payment_intent.succeeded',
            'gateway' => 'stripe',
            'gateway_event_id' => 'evt_unique_1',
        ]);

        // A second row with the SAME (gateway, gateway_event_id) is rejected.
        $threw = false;
        try {
            // Wrap in a savepoint so the rejected insert doesn't poison the
            // surrounding test transaction on Postgres.
            DB::transaction(function (): void {
                PaymentLog::query()->create([
                    'event_type' => 'webhook.payment_intent.succeeded',
                    'gateway' => 'stripe',
                    'gateway_event_id' => 'evt_unique_1',
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'duplicate non-null idempotency key must violate the unique index');
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Bind a PaymentGatewayService mock whose usingDriver() returns itself and
     * whose constructWebhookEvent() returns the crafted event. This bypasses
     * the real gateway's signature verification while exercising the full
     * controller pipeline.
     */
    private function fakeGatewayEvent(string $driver, string $eventType, string $reference, string $eventId): void
    {
        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($driver, $eventType, $reference, $eventId): void {
            $mock->shouldReceive('usingDriver')->andReturnSelf();
            $mock->shouldReceive('constructWebhookEvent')->andReturn([
                'success' => true,
                'event_type' => $eventType,
                'gateway_reference' => $reference,
                'event_id' => $eventId,
                'raw' => ['id' => $eventId, 'type' => $eventType, 'driver' => $driver],
            ]);
        });
    }

    /**
     * @return array{0: Payment, 1: Order, 2: User, 3: Company}
     */
    private function createPaidableChain(): array
    {
        $customer = User::factory()->create();
        $sellerCompany = Company::query()->create(['name' => 'Webhook Co '.uniqid(), 'type' => 'operator']);

        $order = Order::query()->create([
            'order_number' => 'WH-'.str()->uuid(),
            'company_id' => $sellerCompany->id,
            'user_id' => $customer->id,
            'status' => 'pending_payment',
            'currency' => 'USD',
            'total' => 250,
        ]);
        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'total_amount' => 250.00,
            'currency' => 'usd',
            'status' => Invoice::STATUS_PENDING,
        ]);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 250.00,
            'currency' => 'usd',
            'status' => Payment::STATUS_PENDING,
            'payment_method' => 'card',
            'reference_code' => 'pi_'.str()->uuid(),
        ]);

        return [$payment, $order, $customer, $sellerCompany];
    }

    private function orderEarnCount(int $accountId, int $orderId): int
    {
        return LoyaltyTransaction::query()
            ->where('account_id', $accountId)
            ->where('source_type', 'order')
            ->where('source_id', (string) $orderId)
            ->where('type', 'earn')
            ->count();
    }
}
