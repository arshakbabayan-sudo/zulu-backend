<?php

namespace App\Services\Loyalty;

use App\Models\Company;
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

    /** Seller (operator/agent) points earned per $1 of an attributed sale. */
    public const SELLER_POINTS_PER_DOLLAR = 1;

    /** Points awarded for a submitted review. */
    public const POINTS_PER_REVIEW = 50;

    /** Points awarded for a successful referral. */
    public const POINTS_PER_REFERRAL = 500;

    /** Points → currency value for redemption (100 pts = $1). */
    public const POINTS_PER_DOLLAR_REDEMPTION = 100;

    /** Minimum points required to redeem. */
    public const MIN_REDEMPTION_POINTS = 500;

    /** §8c — earned points live this many months before they expire. */
    public const EXPIRY_MONTHS = 24;

    public function getOrCreateAccount(User $user): LoyaltyAccount
    {
        return LoyaltyAccount::query()->firstOrCreate(
            ['user_id' => $user->id, 'account_type' => LoyaltyAccount::TYPE_CUSTOMER],
            ['company_id' => null, 'points_balance' => 0, 'lifetime_points' => 0, 'tier' => 'bronze']
        );
    }

    /** §8c — one loyalty account per seller COMPANY (user_id stays null). */
    public function getOrCreateSellerAccount(Company $company): LoyaltyAccount
    {
        return LoyaltyAccount::query()->firstOrCreate(
            ['company_id' => $company->id, 'account_type' => LoyaltyAccount::TYPE_SELLER],
            ['user_id' => null, 'points_balance' => 0, 'lifetime_points' => 0, 'tier' => 'bronze']
        );
    }

    /**
     * §8c — award seller points to the company an order is attributed to.
     * Idempotent on (account, order, type=earn). No-op for $0 / no company.
     */
    public function earnForSeller(Company $company, Order $order): ?LoyaltyTransaction
    {
        $account = $this->getOrCreateSellerAccount($company);

        $existing = LoyaltyTransaction::query()
            ->where('account_id', $account->id)
            ->where('source_type', 'order')
            ->where('source_id', (string) $order->id)
            ->where('type', 'earn')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $points = (int) floor((float) $order->total * self::SELLER_POINTS_PER_DOLLAR);
        if ($points <= 0) {
            return null;
        }

        return $this->credit($account, $points, 'order', (string) $order->id, "Sale #{$order->order_number}", [
            'order_total' => (float) $order->total,
            'currency' => $order->currency,
            'seller_company_id' => $company->id,
        ]);
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
                // §8c — earned lots expire after EXPIRY_MONTHS (use-it-or-lose-it).
                'expires_at' => now()->addMonths(self::EXPIRY_MONTHS),
            ]);

            $this->recalculateTier($account);

            return $tx;
        });
    }

    /**
     * §8c — expire earned points whose lot is past expires_at. Each lot is
     * processed exactly once (marked expired_at). To stay safe and idempotent we
     * never drive a balance negative: a lot expires up to its own points, capped
     * at the account's current balance (approximate use-it-or-lose-it; exact
     * FIFO lot-vs-redemption matching is a future refinement).
     *
     * @return int number of points expired across all lots
     */
    public function expireStalePoints(): int
    {
        $lots = LoyaltyTransaction::query()
            ->where('type', 'earn')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('expired_at')
            ->orderBy('expires_at')
            ->get();

        $totalExpired = 0;
        foreach ($lots as $lot) {
            $account = LoyaltyAccount::query()->find($lot->account_id);
            if ($account === null) {
                $lot->update(['expired_at' => now()]);

                continue;
            }

            DB::transaction(function () use ($lot, $account, &$totalExpired): void {
                LoyaltyAccount::query()->where('id', $account->id)->lockForUpdate()->first();
                $account->refresh();

                $toExpire = (int) min((int) $lot->points, max(0, (int) $account->points_balance));
                if ($toExpire > 0) {
                    $this->debit($account, $toExpire, 'expire', 'loyalty_lot', (string) $lot->id,
                        'Points expired (earned '.$lot->happened_at?->format('Y-m-d').')');
                    $totalExpired += $toExpire;
                }

                $lot->update(['expired_at' => now()]);
            });
        }

        return $totalExpired;
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
