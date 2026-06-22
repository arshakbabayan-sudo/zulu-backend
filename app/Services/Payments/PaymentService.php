<?php

namespace App\Services\Payments;

use App\Events\PaymentReceived;
use App\Exceptions\PaymentRefundFailedException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private PaymentGatewayService $paymentGatewayService,
        private ?NotificationService $notificationService = null,
        private ?AuditService $auditService = null,
    ) {}

    private function audit(): AuditService
    {
        return $this->auditService ?? app(AuditService::class);
    }

    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, Payment>
     */
    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Payment::query()
            ->where(function ($q) use ($companyIds): void {
                $q->whereHas('invoice.order', function ($query) use ($companyIds): void {
                    $query->whereIn('company_id', $companyIds);
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        if ($companyIds === []) {
            return Payment::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        return Payment::query()
            ->where(function ($q) use ($companyIds): void {
                $q->whereHas('invoice.order', function ($query) use ($companyIds): void {
                    $query->whereIn('company_id', $companyIds);
                });
            })
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{amount?:numeric,status?:string,payment_method?:string|null}  $data
     */
    public function createForInvoice(Invoice $invoice, array $data = []): Payment
    {
        return $invoice->payments()->create([
            'amount' => $data['amount'] ?? $invoice->total_amount,
            'status' => $data['status'] ?? 'pending',
            'payment_method' => $data['payment_method'] ?? null,
        ]);
    }

    /**
     * @param  array{amount?:numeric,currency?:string,payment_method?:string|null,reference_code?:string|null,status?:string,notes?:string|null}  $data
     */
    public function createForPackageOrderInvoice(Invoice $invoice, array $data = []): Payment
    {
        return $invoice->payments()->create([
            'amount' => $data['amount'] ?? $invoice->total_amount,
            'currency' => $data['currency'] ?? $invoice->currency,
            'status' => $data['status'] ?? Payment::STATUS_PENDING,
            'payment_method' => $data['payment_method'] ?? null,
            'reference_code' => $data['reference_code'] ?? null,
            'paid_at' => null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function markPaid(Payment $payment): Payment
    {
        // Audit C3 — idempotency guard. If the payment is ALREADY paid, this is
        // a duplicate/retried call (e.g. a second webhook delivery that slipped
        // past the controller's lock, or the package-order path double-calling)
        // so we MUST NOT re-fire the side-effects (PaymentReceived → invoice/
        // order cascade + payment-received email, and customer/seller loyalty
        // accrual). Return the current state unchanged. A first-time payment is
        // STATUS_PENDING here, so this guard never affects the happy path.
        if ($payment->status === Payment::STATUS_PAID) {
            return $payment->fresh(['invoice']) ?? $payment;
        }

        $payment->status = Payment::STATUS_PAID;
        $payment->paid_at = now();
        $payment->save();

        $fresh = $payment->fresh(['invoice']);

        $this->audit()->log([
            'category' => 'financial',
            'subject_type' => 'Payment',
            'subject_id' => (string) $payment->id,
            'action' => 'paid',
            'context' => [
                'invoice_id' => $fresh?->invoice_id,
                'amount' => $fresh?->amount,
                'currency' => $fresh?->currency,
            ],
        ]);

        try {
            $invoice = $fresh?->invoice;
            if ($invoice !== null) {
                event(new PaymentReceived($fresh, $invoice));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch payment received event', ['error' => $e->getMessage()]);
        }

        // Roadmap 10.06 §6 — loyalty: every PAID payment awards points to the
        // ordering customer; the account is auto-created on first earn
        // (previously only the package-order path earned, so booking/
        // marketplace customers never got a loyalty account). earnFromOrder
        // is idempotent per order, so the package path's own call is harmless.
        try {
            $order = $fresh?->invoice?->order;
            if ($order !== null) {
                $loyalty = app(LoyaltyService::class);
                if ($order->user_id !== null) {
                    $customer = User::query()->find($order->user_id);
                    if ($customer !== null) {
                        $loyalty->earnFromOrder($customer, $order);
                    }
                }

                // §8c — also award SELLER points to the attributed company: the
                // referring agent if there is one, else the supplying operator.
                // Idempotent per (account, order), best-effort.
                $sellerCompanyId = $order->agent_company_id ?? $order->company_id;
                if ($sellerCompanyId !== null) {
                    $sellerCompany = Company::query()->find($sellerCompanyId);
                    if ($sellerCompany !== null) {
                        $loyalty->earnForSeller($sellerCompany, $order);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Loyalty earn on payment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $fresh;
    }

    public function markFailed(Payment $payment): Payment
    {
        $payment->status = Payment::STATUS_FAILED;
        $payment->save();

        $fresh = $payment->fresh(['invoice.order']);
        $userId = $fresh?->invoice?->order?->user_id;

        $this->audit()->log([
            'category' => 'financial',
            'subject_type' => 'Payment',
            'subject_id' => (string) $payment->id,
            'action' => 'failed',
            'context' => [
                'invoice_id' => $fresh?->invoice_id,
                'amount' => $fresh?->amount,
                'currency' => $fresh?->currency,
            ],
        ]);

        if ($userId !== null) {
            $service = $this->notificationService ?? app(NotificationService::class);
            try {
                $orderNumber = (string) ($fresh->invoice->order->order_number ?? '');
                $service->createForEventWithEmail([
                    'user_id' => (int) $userId,
                    'event_type' => 'payment.failed',
                    'title' => 'Payment Failed',
                    'message' => 'Your payment for order '.$orderNumber.' could not be processed. Please try again or contact support.',
                    'subject_type' => 'payment',
                    'subject_id' => null,
                    'priority' => 'critical',
                    'variables' => [
                        'order_number' => $orderNumber,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('payment.failed notification failed', [
                    'payment_id' => $payment->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $fresh;
    }

    /**
     * Refund a paid payment, fully (default) or partially.
     *
     * §8 — a nullable $amountCents enables partial refunds from the admin
     * refund-request queue. A full refund (null, or an amount that brings the
     * cumulative refunded total up to the payment amount) flips the payment to
     * REFUNDED; a strictly partial refund leaves it PAID.
     *
     * Audit C4 (§5) — over-refund guard. A cumulative `refunded_amount` is kept
     * on the payment so repeated partial refunds can never exceed the paid
     * amount. The remaining-cap is computed and the over-cap case is rejected
     * BEFORE any gateway money-out, so a rejected refund never touches the
     * gateway. On success the cumulative total is incremented atomically (an
     * UPDATE ... SET refunded_amount = refunded_amount + ? guarded by an
     * amount-cap WHERE), and the payment is only flipped to REFUNDED once it is
     * fully refunded. A single legitimate full refund behaves exactly as before
     * (cumulative goes 0 → amount, status → REFUNDED).
     *
     * @throws PaymentRefundFailedException
     */
    public function refund(Payment $payment, ?int $amountCents = null): Payment
    {
        if ($payment->status === Payment::STATUS_REFUNDED) {
            return $payment->fresh();
        }

        if ($payment->status !== Payment::STATUS_PAID) {
            throw new PaymentRefundFailedException(
                "Only paid payments can be refunded (current: {$payment->status})",
                $payment->id
            );
        }

        $paymentCents = (int) round(((float) $payment->amount) * 100);
        $alreadyRefundedCents = (int) round(((float) ($payment->refunded_amount ?? 0)) * 100);
        $remainingCents = $paymentCents - $alreadyRefundedCents;

        // Nothing left to give back — treat an exhausted payment as already
        // refunded rather than calling the gateway again.
        if ($remainingCents <= 0) {
            throw new PaymentRefundFailedException(
                'Payment is already fully refunded; nothing left to refund.',
                $payment->id
            );
        }

        // Over-refund cap — an EXPLICIT amount that exceeds the remaining
        // refundable balance is REJECTED (BEFORE any money-out), never silently
        // clamped, so callers can't accidentally over-refund. A null amount is
        // the documented "full refund" request and always means "refund exactly
        // whatever is left" (so a legit single full refund is unchanged, and a
        // re-issued full refund on a partially-refunded payment tops it up).
        if ($amountCents === null) {
            $thisRefundCents = $remainingCents;
        } else {
            if ($amountCents <= 0) {
                throw new PaymentRefundFailedException(
                    'Refund amount must be greater than zero.',
                    $payment->id
                );
            }

            if ($amountCents > $remainingCents) {
                throw new PaymentRefundFailedException(
                    'Refund amount exceeds the remaining refundable balance for this payment.',
                    $payment->id
                );
            }

            $thisRefundCents = $amountCents;
        }

        // Will this refund settle the full payment?
        $fullyRefundsNow = ($alreadyRefundedCents + $thisRefundCents) >= $paymentCents;

        // Issue the gateway money-out. A full settlement passes null (mirrors the
        // original full-refund call shape) so existing gateway behaviour and the
        // single-refund happy path are unchanged.
        $result = $this->paymentGatewayService->refundPaymentIntent(
            $payment,
            $fullyRefundsNow && $amountCents === null ? null : $thisRefundCents
        );
        if (($result['success'] ?? false) !== true) {
            throw new PaymentRefundFailedException($result['error'] ?? 'Gateway refund failed', $payment->id);
        }

        // Atomically book the refund. The WHERE re-asserts the cap at the row
        // level so two refunds racing past the in-memory check still cannot
        // over-refund on the real DB: the second UPDATE matches 0 rows. Money
        // literals are derived from validated integer cents (no user input) and
        // formatted to 2dp so the cap compares like-for-like with no float drift.
        $thisRefundLiteral = $this->sqlMoney($thisRefundCents);
        $paidLiteral = $this->sqlMoney($paymentCents);
        $newStatus = $fullyRefundsNow ? Payment::STATUS_REFUNDED : Payment::STATUS_PAID;

        DB::transaction(function () use ($payment, $thisRefundLiteral, $paidLiteral, $newStatus): void {
            $affected = Payment::query()
                ->whereKey($payment->getKey())
                ->whereRaw('(COALESCE(refunded_amount, 0) + '.$thisRefundLiteral.') <= '.$paidLiteral)
                ->update([
                    'refunded_amount' => DB::raw('COALESCE(refunded_amount, 0) + '.$thisRefundLiteral),
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                // The row-level cap rejected the increment (a concurrent refund
                // already consumed the remaining balance). Money-out already
                // happened above only if the gateway accepted it, so surface a
                // failure rather than silently swallowing — but in practice the
                // controller's lock prevents two gateway calls from this path.
                throw new PaymentRefundFailedException(
                    'Refund amount exceeds the remaining refundable balance for this payment.',
                    $payment->id
                );
            }
        });

        return $payment->fresh();
    }

    /**
     * Render a cents value as a safe decimal literal for use inside a raw
     * SQL increment expression (no user input — derived from validated cents).
     */
    private function sqlMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
