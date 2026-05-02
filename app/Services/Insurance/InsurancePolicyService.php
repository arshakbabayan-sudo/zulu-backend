<?php

namespace App\Services\Insurance;

use App\Models\InsurancePolicy;
use App\Models\InsuranceProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PART 17 — Issues InsurancePolicy records from a paid InsuranceProduct purchase.
 */
class InsurancePolicyService
{
    public function __construct(
        private InsurancePricingService $pricing,
    ) {}

    /**
     * Issue a policy. Idempotent on (order_id, order_item_id, product_id) — re-issuing returns existing.
     *
     * @param  array<int, array{age: int, name?: string, dob?: string, passport?: string}>  $travelers
     */
    public function issue(
        InsuranceProduct $product,
        array $travelers,
        string $coverageStartDate,
        int $coverageDays,
        ?Order $order = null,
        ?OrderItem $orderItem = null,
        ?User $user = null,
    ): InsurancePolicy {
        if (! $product->isActive()) {
            throw new InvalidArgumentException('Insurance product is not active.');
        }
        if ($travelers === []) {
            throw new InvalidArgumentException('At least one traveler required.');
        }

        if ($order !== null && $orderItem !== null) {
            $existing = InsurancePolicy::query()
                ->where('order_id', $order->id)
                ->where('order_item_id', $orderItem->id)
                ->where('product_id', $product->id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $start = CarbonImmutable::parse($coverageStartDate);
        $end = $start->addDays(max(1, $coverageDays) - 1); // inclusive

        $quote = $this->pricing->quote($product, $travelers, $coverageDays);

        return DB::transaction(function () use ($product, $travelers, $start, $end, $coverageDays, $order, $orderItem, $user, $quote): InsurancePolicy {
            return InsurancePolicy::query()->create([
                'policy_number' => $this->generatePolicyNumber(),
                'product_id' => $product->id,
                'order_id' => $order?->id,
                'order_item_id' => $orderItem?->id,
                'user_id' => $user?->id ?? $order?->user_id,
                'insured_persons' => $this->normalizeTravelers($travelers),
                'coverage_start_date' => $start->toDateString(),
                'coverage_end_date' => $end->toDateString(),
                'coverage_days' => $coverageDays,
                'premium_paid' => $quote['premium'],
                'currency' => $quote['currency'],
                'product_snapshot' => [
                    'underwriter_name' => $product->underwriter_name,
                    'product_name' => $product->product_name,
                    'coverage_territory' => $product->coverage_territory,
                    'coverage_details' => $product->coverage_details,
                    'pre_existing_covered' => $product->pre_existing_covered,
                    'snapshot_at' => now()->toIso8601String(),
                ],
                'status' => 'active',
                'issued_at' => now(),
            ]);
        });
    }

    public function cancel(InsurancePolicy $policy, string $reason): InsurancePolicy
    {
        if ($policy->status !== 'active') {
            throw new InvalidArgumentException("Policy is not active (current: {$policy->status}).");
        }

        $policy->status = 'cancelled';
        $policy->cancelled_at = now();
        $policy->cancellation_reason = $reason;
        $policy->save();

        return $policy->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $travelers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTravelers(array $travelers): array
    {
        return array_map(fn (array $t) => [
            'name' => $t['name'] ?? null,
            'dob' => $t['dob'] ?? null,
            'passport' => $t['passport'] ?? null,
            'age_at_issue' => (int) ($t['age'] ?? 0),
        ], $travelers);
    }

    private function generatePolicyNumber(): string
    {
        return 'POL-'.now()->format('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
}
