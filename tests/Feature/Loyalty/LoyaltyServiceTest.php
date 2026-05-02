<?php

namespace Tests\Feature\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LoyaltyServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoyaltyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LoyaltyService::class);
    }

    public function test_get_or_create_account_creates_bronze_default(): void
    {
        $user = User::factory()->create();
        $account = $this->service->getOrCreateAccount($user);

        $this->assertSame('bronze', $account->tier);
        $this->assertSame(0, $account->points_balance);
        $this->assertSame(0, $account->lifetime_points);
    }

    public function test_earn_from_order_awards_points_at_tier_multiplier(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 100); // 100 USD

        $tx = $this->service->earnFromOrder($user, $order);

        $this->assertNotNull($tx);
        $this->assertSame('earn', $tx->type);
        $this->assertSame(100, $tx->points); // bronze 1x
        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(100, $account->points_balance);
        $this->assertSame(100, $account->lifetime_points);
    }

    public function test_earn_from_order_idempotent(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 100);

        $tx1 = $this->service->earnFromOrder($user, $order);
        $tx2 = $this->service->earnFromOrder($user, $order);

        $this->assertSame($tx1->id, $tx2->id);
        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(100, $account->points_balance); // not 200
    }

    public function test_tier_recalculates_on_threshold_cross(): void
    {
        $user = User::factory()->create();
        // Award 600 points → silver (>=500)
        $order = $this->makeOrder($user, 600);
        $this->service->earnFromOrder($user, $order);

        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame('silver', $account->tier);
    }

    public function test_silver_tier_earns_with_125x_multiplier(): void
    {
        $user = User::factory()->create();
        // Promote to silver first
        $account = $this->service->getOrCreateAccount($user);
        $account->lifetime_points = 600;
        $account->tier = 'silver';
        $account->save();

        // Now earn from $100 order → 100 base × 1.25 = 125
        $order = $this->makeOrder($user, 100);
        $tx = $this->service->earnFromOrder($user, $order);

        $this->assertSame(125, $tx->points);
    }

    public function test_review_and_referral_award_points(): void
    {
        $user = User::factory()->create();

        $reviewTx = $this->service->earnForReview($user, 'review-uuid-1');
        $this->assertSame(50, $reviewTx->points);

        $refTx = $this->service->earnForReferral($user, 'referral-1');
        $this->assertSame(500, $refTx->points);

        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(550, $account->points_balance);
    }

    public function test_redeem_validates_minimum(): void
    {
        $user = User::factory()->create();
        $this->service->getOrCreateAccount($user)->update(['points_balance' => 1000, 'lifetime_points' => 1000]);
        $order = $this->makeOrder($user, 1000);

        $this->expectException(InvalidArgumentException::class);
        $this->service->redeemForOrder($user, $order, 100); // below 500 min
    }

    public function test_redeem_validates_balance(): void
    {
        $user = User::factory()->create();
        $this->service->getOrCreateAccount($user)->update(['points_balance' => 200]);
        $order = $this->makeOrder($user, 1000);

        $this->expectException(InvalidArgumentException::class);
        $this->service->redeemForOrder($user, $order, 500); // exceeds balance
    }

    public function test_redeem_validates_max_20_percent_of_order(): void
    {
        $user = User::factory()->create();
        $this->service->getOrCreateAccount($user)->update(['points_balance' => 5000]);
        $order = $this->makeOrder($user, 100); // 20% = $20 = 2000 pts max

        $this->expectException(InvalidArgumentException::class);
        $this->service->redeemForOrder($user, $order, 5000); // = $50 discount, exceeds 20%
    }

    public function test_redeem_succeeds_within_limits(): void
    {
        $user = User::factory()->create();
        $this->service->getOrCreateAccount($user)->update(['points_balance' => 5000]);
        $order = $this->makeOrder($user, 1000); // 20% = $200 = 20000 pts max

        $result = $this->service->redeemForOrder($user, $order, 1000); // = $10 discount

        $this->assertSame(1000, $result['redemption']->points_redeemed);
        $this->assertSame(10.0, (float) $result['redemption']->discount_amount);
        $this->assertSame('applied', $result['redemption']->status);

        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(4000, $account->points_balance);
    }

    public function test_manual_adjust_positive_credits_account(): void
    {
        $user = User::factory()->create();
        $this->service->adjustManually($user, 250, 'Goodwill bonus');

        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(250, $account->points_balance);
    }

    public function test_manual_adjust_negative_debits_account(): void
    {
        $user = User::factory()->create();
        $this->service->getOrCreateAccount($user)->update(['points_balance' => 500]);

        $this->service->adjustManually($user, -100, 'Reversal');

        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(400, $account->points_balance);
    }

    private function makeOrder(User $user, float $total): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD-LOY-'.str()->random(6),
            'user_id' => $user->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
        ]);
    }
}
