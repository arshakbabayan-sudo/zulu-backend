<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Commissions\CommissionRuleResolver;
use App\Services\Commissions\DTOs\CommissionResolutionContext;
use Illuminate\Support\Facades\Log;

/**
 * P0-1 step 1.2 — decide whether (and how) to split a Stripe charge.
 *
 * For every PaymentIntent created via {@see Gateways\StripeGateway} we
 * ask this service: "given the seller behind this payment, what's the
 * application_fee + transfer destination?". Two outcomes:
 *
 *   - Split plan (array): the seller has a Stripe Connect Express account
 *     with charges + payouts enabled, AND the CommissionRuleResolver picked
 *     a rule. StripeGateway adds application_fee_amount + transfer_data
 *     [destination] to the intent. Stripe automatically routes the platform
 *     fee to ZULU and the rest to the seller's connected account.
 *
 *   - null: seller not yet onboarded, no commission rule, currency mismatch
 *     we can't safely resolve, or the resolved fee is 0/≥ gross. Caller
 *     falls back to a plain platform charge — ZULU collects everything,
 *     pays the seller out-of-band (manual settlement). Logged so we have
 *     an audit trail of why split was skipped.
 *
 * The fee number we compute MUST equal what {@see App\Services\Commissions
 * \CommissionService::accrueForOrder} computes later (it uses the same
 * Resolver against the same context, so both pick the same rule). If they
 * ever drift, the post-payment CommissionTransaction.commission_amount
 * would not match Stripe's realized application_fee — a real bug.
 */
class StripeSplitService
{
    public function __construct(
        private readonly CommissionRuleResolver $resolver,
    ) {}

    /**
     * Resolve a split plan for the given payment. Returns null when we
     * should NOT split (i.e. caller should issue a plain charge).
     *
     * @return array{
     *   destination: string,
     *   application_fee_cents: int,
     *   currency: string,
     *   seller_company_id: int,
     *   service_type: string,
     *   rule_id: int|string,
     *   reason: string,
     * }|null
     */
    public function computeForPayment(Payment $payment): ?array
    {
        $payment->loadMissing('invoice.order.items', 'invoice.order.company');
        $order = $payment->invoice?->order;
        if (! $order instanceof Order) {
            $this->logSkip($payment, 'no_order_on_payment');

            return null;
        }

        $company = $order->company;
        if ($company === null) {
            $this->logSkip($payment, 'no_seller_company');

            return null;
        }
        if (! $company->hasStripeConnectReady()) {
            $this->logSkip($payment, 'seller_not_connect_ready', [
                'company_id' => $company->id,
                'stripe_connect_id' => $company->stripe_connect_id,
                'charges_enabled' => (bool) $company->stripe_charges_enabled,
                'payouts_enabled' => (bool) $company->stripe_payouts_enabled,
            ]);

            return null;
        }

        $serviceType = $this->resolveServiceType($order);
        $categoryId = $this->resolveCategoryId($order);
        $currency = strtoupper((string) ($payment->currency ?? $order->currency ?? 'USD'));
        $grossAmount = (string) ($payment->amount ?? $order->total ?? '0');

        try {
            $ctx = CommissionResolutionContext::make(
                sellerId: (int) $company->id,
                serviceType: $serviceType,
                opts: [
                    'atTime' => now(),
                    'categoryId' => $categoryId,
                ],
            );
            $result = $this->resolver->resolve($ctx);
        } catch (\Throwable $e) {
            Log::warning('Stripe split commission resolution failed', [
                'payment_id' => $payment->id,
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
            $this->logSkip($payment, 'commission_resolver_error', ['error' => $e->getMessage()]);

            return null;
        }

        $rule = $result->chosenRule;
        if ($rule === null) {
            $this->logSkip($payment, 'no_commission_rule', [
                'company_id' => $company->id,
                'service_type' => $serviceType,
            ]);

            return null;
        }

        if ($rule->type === 'percentage') {
            $fee = bcdiv(bcmul($grossAmount, (string) $rule->percentage_value, 8), '100', 4);
        } elseif ($rule->type === 'fixed') {
            if ($rule->fixed_currency !== null && strtoupper((string) $rule->fixed_currency) !== $currency) {
                // We can't safely split when the platform fee is denominated
                // in a different currency than the charge — Stripe needs the
                // fee in the charge currency. Skip and fall back to plain
                // charge; a follow-up can either reject the order or do FX.
                $this->logSkip($payment, 'currency_mismatch_fixed_rule', [
                    'rule_currency' => $rule->fixed_currency,
                    'charge_currency' => $currency,
                ]);

                return null;
            }
            $fee = bcadd((string) $rule->fixed_value, '0', 4);
        } else {
            $fee = bcadd(
                bcdiv(bcmul($grossAmount, (string) $rule->percentage_value, 8), '100', 4),
                (string) $rule->fixed_value,
                4
            );
        }

        $applicationFeeCents = (int) round(((float) $fee) * 100);
        $grossCents = (int) round(((float) $grossAmount) * 100);

        if ($applicationFeeCents <= 0) {
            $this->logSkip($payment, 'fee_non_positive', [
                'rule_id' => $rule->id,
                'fee' => $fee,
            ]);

            return null;
        }
        if ($applicationFeeCents >= $grossCents) {
            // Stripe rejects application_fee_amount >= amount. A rule that
            // computes a fee at or above gross is misconfigured for this
            // charge — skip the split, ZULU collects the full amount, the
            // post-payment CommissionService will surface the data issue.
            $this->logSkip($payment, 'fee_exceeds_gross', [
                'rule_id' => $rule->id,
                'application_fee_cents' => $applicationFeeCents,
                'gross_cents' => $grossCents,
            ]);

            return null;
        }

        return [
            'destination' => (string) $company->stripe_connect_id,
            'application_fee_cents' => $applicationFeeCents,
            'currency' => strtolower($currency),
            'seller_company_id' => (int) $company->id,
            'service_type' => $serviceType,
            'rule_id' => $rule->id,
            'reason' => $result->reason ?? 'resolved',
        ];
    }

    /**
     * Mirror App\Services\Commissions\CommissionService::accrueForOrder()'s
     * service-type extraction: package_order legacy origin overrides the
     * first item; otherwise we use first item's item_type; default
     * 'general' if neither is set.
     */
    private function resolveServiceType(Order $order): string
    {
        $legacyOrigin = is_array($order->metadata) ? ($order->metadata['legacy_origin'] ?? null) : null;
        if ($legacyOrigin === 'package_order') {
            return 'package';
        }

        $firstItem = $order->items?->first();

        return (string) ($firstItem?->item_type ?? 'general');
    }

    private function resolveCategoryId(Order $order): ?int
    {
        $firstItem = $order->items?->first();
        $snapshot = is_array($firstItem?->service_snapshot) ? $firstItem->service_snapshot : [];

        return isset($snapshot['category_id']) ? (int) $snapshot['category_id'] : null;
    }

    /**
     * Persist a "split skipped" event into payment_logs so we can audit
     * why a given payment didn't get split. Non-fatal — failure here must
     * never block payment creation.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logSkip(Payment $payment, string $reason, array $extra = []): void
    {
        try {
            \App\Models\PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'split.skipped',
                'gateway' => 'stripe',
                'response_payload' => array_merge(['reason' => $reason], $extra),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stripe split log failed', [
                'payment_id' => $payment->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
