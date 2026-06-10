<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap 10.06 §6 — a paid payment auto-creates the customer's loyalty
 * account and credits points for the order (previously only the
 * package-order path earned; booking/marketplace customers got nothing).
 */
class LoyaltyEarnOnPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_paid_creates_loyalty_account_and_credits_points(): void
    {
        $customer = User::factory()->create();
        $payment = $this->createPaymentChain($customer);

        $this->assertDatabaseMissing('loyalty_accounts', ['user_id' => $customer->id]);

        app(PaymentService::class)->markPaid($payment);

        $account = LoyaltyAccount::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($account, 'loyalty account should be auto-created on first paid payment');
        $this->assertGreaterThan(0, $account->points_balance);

        // Idempotent: paying again (or the package path double-calling) must
        // not double-credit the same order.
        app(PaymentService::class)->markPaid($payment->fresh());
        $this->assertSame(
            (int) $account->fresh()->points_balance,
            (int) $account->points_balance,
        );
    }

    private function createPaymentChain(User $customer): Payment
    {
        $company = Company::query()->create(['name' => 'Loyalty Co '.uniqid(), 'type' => 'operator']);
        $order = Order::query()->create([
            'order_number' => 'LY-'.str()->uuid(),
            'company_id' => $company->id,
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

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 250.00,
            'currency' => 'usd',
            'status' => Payment::STATUS_PENDING,
            'payment_method' => 'card',
        ]);
    }
}
