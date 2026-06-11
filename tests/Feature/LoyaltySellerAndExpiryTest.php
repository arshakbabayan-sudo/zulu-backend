<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap 10.06 §8c — seller (operator/agent) loyalty points + points expiry.
 */
class LoyaltySellerAndExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_earn_for_seller_creates_company_account_and_is_idempotent(): void
    {
        $operator = Company::query()->create(['name' => 'Seller Op '.uniqid(), 'type' => 'operator']);
        $order = $this->order($operator, 250.00);

        $svc = app(LoyaltyService::class);
        $tx = $svc->earnForSeller($operator, $order);

        $this->assertNotNull($tx);
        $account = LoyaltyAccount::query()
            ->where('company_id', $operator->id)
            ->where('account_type', LoyaltyAccount::TYPE_SELLER)
            ->first();
        $this->assertNotNull($account, 'a seller loyalty account should be created');
        $this->assertNull($account->user_id);
        $this->assertSame(250, (int) $account->points_balance);
        $this->assertNotNull($tx->expires_at, 'earned lot should carry an expiry');

        // Idempotent per order — re-earning does not double-credit.
        $svc->earnForSeller($operator, $order);
        $this->assertSame(250, (int) $account->fresh()->points_balance);
    }

    public function test_customer_and_seller_accounts_coexist_for_the_same_order(): void
    {
        $operator = Company::query()->create(['name' => 'Coexist Op '.uniqid(), 'type' => 'operator']);
        $customer = User::factory()->create();
        $order = $this->order($operator, 100.00, $customer);

        $svc = app(LoyaltyService::class);
        $svc->earnFromOrder($customer, $order);
        $svc->earnForSeller($operator, $order);

        $this->assertDatabaseHas('loyalty_accounts', [
            'user_id' => $customer->id,
            'account_type' => LoyaltyAccount::TYPE_CUSTOMER,
        ]);
        $this->assertDatabaseHas('loyalty_accounts', [
            'company_id' => $operator->id,
            'account_type' => LoyaltyAccount::TYPE_SELLER,
        ]);
    }

    public function test_expire_stale_points_debits_past_due_lots_once(): void
    {
        $operator = Company::query()->create(['name' => 'Expiry Op '.uniqid(), 'type' => 'operator']);
        $order = $this->order($operator, 300.00);

        $svc = app(LoyaltyService::class);
        $tx = $svc->earnForSeller($operator, $order);
        $account = LoyaltyAccount::query()->where('company_id', $operator->id)->firstOrFail();
        $this->assertSame(300, (int) $account->points_balance);

        // Force the lot past its expiry window.
        LoyaltyTransaction::query()->where('id', $tx->id)->update(['expires_at' => now()->subDay()]);

        $expired = $svc->expireStalePoints();

        $this->assertSame(300, $expired);
        $this->assertSame(0, (int) $account->fresh()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', [
            'account_id' => $account->id,
            'type' => 'expire',
        ]);
        $this->assertNotNull($tx->fresh()->expired_at, 'the lot should be marked processed');

        // Idempotent — a second sweep does nothing.
        $this->assertSame(0, $svc->expireStalePoints());
    }

    public function test_expiry_never_drives_balance_negative(): void
    {
        $operator = Company::query()->create(['name' => 'NoNeg Op '.uniqid(), 'type' => 'operator']);
        $order = $this->order($operator, 100.00);

        $svc = app(LoyaltyService::class);
        $tx = $svc->earnForSeller($operator, $order);
        $account = LoyaltyAccount::query()->where('company_id', $operator->id)->firstOrFail();

        // Manually drain the balance (e.g. redeemed elsewhere) then expire the lot.
        $account->update(['points_balance' => 30]);
        LoyaltyTransaction::query()->where('id', $tx->id)->update(['expires_at' => now()->subDay()]);

        $expired = $svc->expireStalePoints();

        $this->assertSame(30, $expired, 'only the remaining balance is expired');
        $this->assertSame(0, (int) $account->fresh()->points_balance);
    }

    private function order(Company $operator, float $total, ?User $customer = null): Order
    {
        return Order::query()->create([
            'order_number' => 'SL-'.str()->uuid(),
            'company_id' => $operator->id,
            'user_id' => $customer?->id,
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
        ]);
    }
}
