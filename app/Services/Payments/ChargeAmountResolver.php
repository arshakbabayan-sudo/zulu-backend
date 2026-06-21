<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderPricingSnapshot;
use App\Services\Pricing\SellerFxRateService;
use Illuminate\Support\Facades\Log;

/**
 * B3 — server-authoritative customer-charge resolver (seller FX model).
 *
 * Arshak's decided currency model: when a customer pays for an order whose
 * currency is NOT the charge currency (Armenia phase = AMD), the amount
 * actually charged through the gateway is computed SERVER-SIDE as
 *   chargeAmount = order.total × seller-effective-rate
 * (seller-effective-rate = base CBA/manual rate + the operator's ABSOLUTE
 * margin), so ZULU takes no FX risk. The resolved rate is FROZEN into an
 * order-level order_pricing_snapshots.snapshot_json.fx row at charge time so
 * later rate moves never re-price an already-placed order.
 *
 * 🔴 SAFE-BY-DEFAULT (non-negotiable). This service NEVER changes today's
 * behaviour unless a seller FX setting is actually configured AND a base rate
 * exists AND the order is not already in the charge currency. In every other
 * case it returns the order's native amount/currency verbatim and writes no
 * snapshot:
 *   - order already in AMD                 → native amount/currency, no snapshot
 *   - SellerFxRateService::convert()=null  → native amount/currency, no snapshot
 *     (no setting at any scope, or no CBA/manual base rate)
 *   - convert() throws                     → caught, logged, native, no snapshot
 * A platform with zero seller_fx_rates rows therefore sees ZERO behaviour
 * change — B3 is non-breaking by construction.
 *
 * The conversion changes ONLY what is CHARGED (the Payment.amount/currency)
 * plus the frozen audit snapshot. The Order row (total/currency) is left
 * native and is not touched here. Downstream ledger records (invoice,
 * per-item snapshots, commissions, entitlements, loyalty, webhooks) remain in
 * the ORDER currency — reconciliation of "charged AMD" vs "recorded native"
 * is deliberately OUT OF SCOPE for B3 (flagged as a known risk).
 */
class ChargeAmountResolver
{
    /**
     * Marker stored in snapshot_json.fx.kind so the order-level charge-FX row
     * is distinguishable from the per-item creation-time fx snapshots and can
     * be looked up for idempotency.
     */
    public const FX_KIND = 'seller_charge_fx';

    /**
     * The currency the customer is charged in for the Armenia phase. The whole
     * step is a no-op for orders already in this currency.
     */
    public const CHARGE_CURRENCY = 'AMD';

    public function __construct(
        private readonly SellerFxRateService $sellerFxRateService,
    ) {}

    /**
     * Resolve the server-authoritative {amount, currency} the gateway must
     * charge for $order, freezing the seller FX snapshot when a conversion
     * applies. Call this INSIDE the payment-intent DB transaction.
     *
     * $nativeTotal / $nativeCurrency are the values the caller already
     * computed from the Order (NEVER from the request). They are returned
     * unchanged whenever the safe-by-default fallback fires.
     *
     * @return array{amount: float, currency: string, converted: bool}
     */
    public function resolveForOrder(Order $order, float $nativeTotal, string $nativeCurrency): array
    {
        $nativeCurrency = strtoupper($nativeCurrency);

        $native = [
            'amount' => $nativeTotal,
            'currency' => $nativeCurrency,
            'converted' => false,
        ];

        // SAFE GUARD 1 — already in the charge currency → zero change (no DB read).
        if ($nativeCurrency === self::CHARGE_CURRENCY) {
            return $native;
        }

        // SAFE GUARD 2 — never let an FX failure break a charge. The ENTIRE FX
        // path (the frozen-snapshot read, the rate resolution, and the snapshot
        // write) is wrapped: any throw falls back to today's native behaviour
        // (mirrors the StripeSplitService try/catch precedent in StripeGateway).
        // A broken FX path can therefore never break a charge that works today.
        try {
            // FROZEN-RATE IMMUTABILITY — if this order already has a frozen
            // charge-FX snapshot (idempotent payment-intent reuse / retry),
            // return the ORIGINALLY frozen amount/currency. We deliberately do
            // NOT re-resolve, so a rate that moved between the first and second
            // call can never re-price an already-placed order.
            $frozen = $this->existingFrozenFx($order);
            if ($frozen !== null) {
                $fx = $frozen->snapshot_json['fx'] ?? [];
                $targetCurrency = (string) ($fx['target_currency'] ?? self::CHARGE_CURRENCY);

                return [
                    'amount' => (float) ($fx['amount_target'] ?? $nativeTotal),
                    'currency' => $targetCurrency,
                    'converted' => $targetCurrency !== $nativeCurrency,
                ];
            }

            $operatorCompanyId = $order->company_id !== null ? (int) $order->company_id : null;
            $agentCompanyId = $order->agent_company_id !== null ? (int) $order->agent_company_id : null;

            $res = $this->sellerFxRateService->convert(
                (string) $nativeTotal,
                $nativeCurrency,
                self::CHARGE_CURRENCY,
                $operatorCompanyId,
                $agentCompanyId,
            );

            // SAFE GUARD 3 — NULL = no setting at any scope, or no base rate.
            // Fall through to today's native charge, write no snapshot.
            if ($res === null) {
                return $native;
            }

            // Defensive: a same-currency / rate-1 result is a no-op too. (Guard 1
            // already short-circuits AMD, so this only fires on an unexpected
            // resolver response; treat it as native to avoid a meaningless row.)
            if ($res['target_currency'] === $nativeCurrency || $res['rate'] === '1') {
                return $native;
            }

            // A conversion applies — freeze the rate (idempotently) and charge AMD.
            $this->freezeFxSnapshot($order, $nativeTotal, $nativeCurrency, $res);

            return [
                'amount' => (float) $res['amount'],
                'currency' => $res['target_currency'],
                'converted' => true,
            ];
        } catch (\Throwable $e) {
            Log::warning('Seller FX charge resolution threw — charging native amount/currency', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $native;
        }
    }

    /**
     * Find an existing frozen charge-FX snapshot for $order, or null. Used so a
     * later (rate-changed) call reads the ORIGINAL frozen rate instead of
     * re-resolving.
     */
    public function existingFrozenFx(Order $order): ?OrderPricingSnapshot
    {
        return OrderPricingSnapshot::query()
            ->where('order_id', $order->id)
            ->get()
            ->first(function (OrderPricingSnapshot $row): bool {
                $fx = $row->snapshot_json['fx'] ?? null;

                return is_array($fx) && ($fx['kind'] ?? null) === self::FX_KIND;
            });
    }

    /**
     * Write the immutable order-level charge-FX snapshot row, ONCE per order.
     *
     * Idempotent: if a charge-FX snapshot already exists (idempotent
     * payment-intent reuse, retry, or a later rate-changed call), we do NOT
     * write a second row — the FIRST frozen rate stands, so an already-placed
     * order is never re-priced.
     *
     * @param  array{amount: float, rate: string, target_currency: string, source: ?string, setting_id: ?int}  $res
     */
    private function freezeFxSnapshot(Order $order, float $nativeTotal, string $nativeCurrency, array $res): void
    {
        if ($this->existingFrozenFx($order) !== null) {
            return;
        }

        // order_pricing_snapshots.offer_id is NOT NULL but has NO FK (index
        // only) — this is an order-level fx row, not tied to one item, so a 0
        // sentinel is safe. zulu_markup_type has a CHECK ('percentage','fixed',
        // 'legacy'); use 'legacy'. currency is char(3).
        OrderPricingSnapshot::query()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'offer_id' => 0,
            'supplier_net' => $nativeTotal,
            'zulu_markup_value' => 0,
            'zulu_markup_type' => 'legacy',
            'agent_net' => null,
            'customer_paid' => $res['amount'],
            'agent_margin' => null,
            'rule_id_applied' => null,
            'currency' => $res['target_currency'],
            'snapshot_json' => [
                'fx' => [
                    'kind' => self::FX_KIND,
                    'source_currency' => $nativeCurrency,
                    'target_currency' => $res['target_currency'],
                    'rate' => $res['rate'],
                    'amount_source' => $nativeTotal,
                    'amount_target' => $res['amount'],
                    'setting_id' => $res['setting_id'],
                    'setting_source' => $res['source'],
                    'frozen_at' => now()->toIso8601String(),
                ],
            ],
            'created_at' => now(),
        ]);
    }
}
