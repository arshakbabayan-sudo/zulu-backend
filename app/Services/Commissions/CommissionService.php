<?php

namespace App\Services\Commissions;

use App\Models\Booking;
use App\Models\CommissionResolutionLog;
use App\Models\CommissionRule;
use App\Models\CommissionTransaction;
use App\Models\PackageOrder;
use App\Services\Commissions\DTOs\CommissionResolutionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    public function __construct(
        private CommissionRuleResolver $resolver,
    ) {}

    /**
     * Accrue commission for a Package Order.
     */
    public function accrueForPackageOrder(PackageOrder $packageOrder): ?CommissionTransaction
    {
        return $this->accrue(
            $packageOrder->company_id,
            'package',
            (string) $packageOrder->final_total_snapshot,
            $packageOrder->currency,
            null,
            null
        );
    }

    /**
     * Accrue commission for a generic Booking.
     */
    public function accrueForBooking(Booking $booking): ?CommissionTransaction
    {
        return $this->accrue(
            $booking->company_id,
            'general',
            (string) $booking->total_price,
            strtoupper((string) ($booking->currency ?? 'USD')),
            null,
            null
        );
    }

    /**
     * Generic commission calculation and record creation.
     */
    private function accrue(
        int $sellerId,
        string $serviceType,
        string $baseAmount,
        string $baseCurrency,
        ?int $orderId,
        ?int $orderItemId
    ): ?CommissionTransaction {
        try {
            $ctx = CommissionResolutionContext::make(
                sellerId: $sellerId,
                serviceType: $serviceType,
                opts: ['atTime' => now()],
            );
            $result = $this->resolver->resolve($ctx);
            $rule = $result->chosenRule;

            if ($rule === null) {
                return null;
            }

            $fxRate = null;
            if ($rule->type === 'percentage') {
                $commission = bcdiv(bcmul($baseAmount, (string) $rule->percentage_value, 8), '100', 4);
            } elseif ($rule->type === 'fixed') {
                if ($rule->fixed_currency !== null && strtoupper((string) $rule->fixed_currency) !== strtoupper($baseCurrency)) {
                    Log::warning('Commission fixed currency mismatch during accrual', [
                        'seller_id' => $sellerId,
                        'rule_id' => $rule->id,
                        'fixed_currency' => $rule->fixed_currency,
                        'base_currency' => $baseCurrency,
                    ]);
                }

                $commission = bcadd((string) $rule->fixed_value, '0', 4);
            } else {
                $commission = bcadd(
                    bcdiv(bcmul($baseAmount, (string) $rule->percentage_value, 8), '100', 4),
                    (string) $rule->fixed_value,
                    4
                );
            }

            return DB::transaction(function () use (
                $orderId,
                $orderItemId,
                $rule,
                $sellerId,
                $baseAmount,
                $baseCurrency,
                $commission,
                $fxRate,
                $result
            ): CommissionTransaction {
                $transaction = CommissionTransaction::query()->create([
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'rule_id' => $rule->id,
                    'seller_id' => $sellerId,
                    'base_amount' => $baseAmount,
                    'base_currency' => $baseCurrency,
                    'commission_amount' => $commission,
                    'commission_currency' => $baseCurrency,
                    'net_to_seller' => bcsub($baseAmount, $commission, 4),
                    'fx_rate' => $fxRate,
                    'snapshot' => [
                        'rule_id' => $rule->id,
                        'type' => $rule->type,
                        'level' => $rule->level,
                        'percentage_value' => $rule->percentage_value,
                        'fixed_value' => $rule->fixed_value,
                        'fixed_currency' => $rule->fixed_currency,
                        'scope_id' => $rule->scope_id,
                        'service_type' => $rule->service_type,
                        'effective_from' => $rule->effective_from?->toIso8601String(),
                    ],
                    'computed_at' => now(),
                ]);

                CommissionResolutionLog::query()->create([
                    'transaction_id' => $transaction->id,
                    'candidate_rules' => array_map(
                        static fn (CommissionRule $candidate): string => $candidate->id,
                        $result->candidateRules
                    ),
                    'chosen_rule_id' => $rule->id,
                    'reason' => $result->reason,
                ]);

                return $transaction;
            });
        } catch (\Throwable $e) {
            Log::warning('Commission accrual failed', [
                'seller_id' => $sellerId,
                'service_type' => $serviceType,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
