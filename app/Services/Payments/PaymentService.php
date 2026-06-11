<?php

namespace App\Services\Payments;

use App\Events\PaymentReceived;
use App\Exceptions\PaymentRefundFailedException;
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
            if ($order !== null && $order->user_id !== null) {
                $customer = User::query()->find($order->user_id);
                if ($customer !== null) {
                    app(LoyaltyService::class)->earnFromOrder($customer, $order);
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
     * refund-request queue. A full refund (null, or an amount >= the payment
     * total) flips the payment to REFUNDED; a strictly partial refund leaves it
     * PAID (there is no partially_refunded state) — the partial amount is tracked
     * on the RefundRequest instead.
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
        $isFull = $amountCents === null || $amountCents >= $paymentCents;

        $result = $this->paymentGatewayService->refundPaymentIntent($payment, $isFull ? null : $amountCents);
        if (($result['success'] ?? false) !== true) {
            throw new PaymentRefundFailedException($result['error'] ?? 'Gateway refund failed', $payment->id);
        }

        if ($isFull) {
            DB::transaction(function () use ($payment): void {
                $payment->status = Payment::STATUS_REFUNDED;
                $payment->save();
            });
        }

        return $payment->fresh();
    }
}
