<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoyaltyService
{
    /** Points earned per $1 spent (before tier multiplier). */
    public const POINTS_PER_DOLLAR = 1;

    /** Points awarded for a submitted review. */
    public const POINTS_PER_REVIEW = 50;

    /** Points awarded for a successful referral. */
    public const POINTS_PER_REFERRAL = 500;

    /** Points → currency value for redemption (100 pts = $1). */
    public const POINTS_PER_DOLLAR_REDEMPTION = 100;

    /** Minimum points required to redeem. */
    public const MIN_REDEMPTION_POINTS = 500;

    public function getOrCreateAccount(User $user): LoyaltyAccount
    {
        return LoyaltyAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['points_balance' => 0, 'lifetime_points' => 0, 'tier' => 'bronze']
        );
    }

    /**
     * Award points for an order payment. Idempotent on (order_id, type=earn, source_type=order).
     */
    public function earnFromOrder(User $user, Order $order): ?LoyaltyTransaction
    {
        $account = $this->getOrCreateAccount($user);

        $existing = LoyaltyTransaction::query()
            ->where('account_id', $account->id)
            ->where('source_type', 'order')
            ->where('source_id', (string) $order->id)
            ->where('type', 'earn')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $basePoints = (int) floor((float) $order->total * self::POINTS_PER_DOLLAR);
        if ($basePoints <= 0) {
            return null;
        }

        $multiplier = $account->tierMultiplier();
        $earned = (int) floor($basePoints * $multiplier);

        return $this->credit($account, $earned, 'order', (string) $order->id, "Order paid #{$order->order_number}", [
            'order_total' => (float) $order->total,
            'currency' => $order->currency,
            'tier_at_earn' => $account->tier,
            'multiplier' => $multiplier,
        ]);
    }

    public function earnForReview(User $user, string $reviewId): LoyaltyTransaction
    {
        $account = $this->getOrCreateAccount($user);

        return $this->credit($account, self::POINTS_PER_REVIEW, 'review', $reviewId, 'Review submitted');
    }

    public function earnForReferral(User $user, string $referralId): LoyaltyTransaction
    {
        $account = $this->getOrCreateAccount($user);

        return $this->credit($account, self::POINTS_PER_REFERRAL, 'referral', $referralId, 'Successful referral');
    }

    public function adjustManually(User $user, int $points, string $reason): LoyaltyTransaction
    {
        $account = $this->getOrCreateAccount($user);

        if ($points >= 0) {
            return $this->credit($account, $points, 'manual', null, 'ADMIN: '.$reason);
        }

        return $this->debit($account, abs($points), 'adjust', 'manual', null, 'ADMIN: '.$reason);
    }

    /**
     * Redeem points against an Order. Returns redemption record + transaction.
     *
     * @return array{redemption: LoyaltyRedemption, transaction: LoyaltyTransaction}
     */
    public function redeemForOrder(User $user, Order $order, int $points): array
    {
        if ($points < self::MIN_REDEMPTION_POINTS) {
            throw new InvalidArgumentException('Minimum redemption is '.self::MIN_REDEMPTION_POINTS.' points.');
        }

        $account = $this->getOrCreateAccount($user);
        if ($account->points_balance < $points) {
            throw new InvalidArgumentException('Insufficient points balance.');
        }

        $discount = round($points / self::POINTS_PER_DOLLAR_REDEMPTION, 2);
        $maxAllowed = round((float) $order->total * 0.20, 2); // max 20% of order value
        if ($discount > $maxAllowed) {
            throw new InvalidArgumentException(
                "Redemption discount {$discount} exceeds 20% of order total {$maxAllowed}."
            );
        }

        return DB::transaction(function () use ($account, $order, $points, $discount): array {
            $tx = $this->debit($account, $points, 'redeem', 'order', (string) $order->id, "Redeemed for order #{$order->order_number}");

            $redemption = LoyaltyRedemption::query()->create([
                'account_id' => $account->id,
                'order_id' => $order->id,
                'points_redeemed' => $points,
                'discount_amount' => $discount,
                'currency' => $order->currency,
                'status' => 'applied',
                'redeemed_at' => now(),
            ]);

            return ['redemption' => $redemption, 'transaction' => $tx];
        });
    }

    public function recalculateTier(LoyaltyAccount $account): LoyaltyAccount
    {
        $newTier = 'bronze';
        foreach (LoyaltyAccount::TIER_THRESHOLDS as $tier => $threshold) {
            if ($account->lifetime_points >= $threshold) {
                $newTier = $tier;
            }
        }

        if ($account->tier !== $newTier) {
            $account->tier = $newTier;
            $account->save();
        }

        return $account->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function credit(LoyaltyAccount $account, int $points, string $sourceType, ?string $sourceId, string $reason, ?array $metadata = null): LoyaltyTransaction
    {
        return DB::transaction(function () use ($account, $points, $sourceType, $sourceId, $reason, $metadata): LoyaltyTransaction {
            LoyaltyAccount::query()->where('id', $account->id)->lockForUpdate()->first();
            $account->refresh();

            $account->points_balance += $points;
            $account->lifetime_points += $points;
            $account->last_activity_at = now();
            $account->save();

            $tx = LoyaltyTransaction::query()->create([
                'account_id' => $account->id,
                'type' => 'earn',
                'points' => $points,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => $reason,
                'metadata' => $metadata,
                'happened_at' => now(),
            ]);

            $this->recalculateTier($account);

            return $tx;
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function debit(LoyaltyAccount $account, int $points, string $type, string $sourceType, ?string $sourceId, string $reason, ?array $metadata = null): LoyaltyTransaction
    {
        return DB::transaction(function () use ($account, $points, $type, $sourceType, $sourceId, $reason, $metadata): LoyaltyTransaction {
            LoyaltyAccount::query()->where('id', $account->id)->lockForUpdate()->first();
            $account->refresh();

            $account->points_balance -= $points;
            $account->last_activity_at = now();
            $account->save();

            return LoyaltyTransaction::query()->create([
                'account_id' => $account->id,
                'type' => $type,
                'points' => -$points,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => $reason,
                'metadata' => $metadata,
                'happened_at' => now(),
            ]);
        });
    }
}
