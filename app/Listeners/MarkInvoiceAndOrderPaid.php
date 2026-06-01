<?php

namespace App\Listeners;

use App\Events\PaymentReceived;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P0-1 step 1.5 (extension) — cascade Payment.paid to Invoice + Order.
 *
 * PaymentService::markPaid flips Payment.status to paid + paid_at, then
 * dispatches PaymentReceived. Without this listener the Order stays in
 * `pending_payment` and the Invoice stays in `pending`, so the customer's
 * booking detail page reads "awaiting confirmation" forever even though
 * Stripe already charged them. We close the loop here:
 *
 *   Invoice.status → paid
 *   Order.status   → paid (only when not already paid/confirmed/cancelled)
 *
 * Runs synchronously (not queued) so the database state matches the
 * webhook response by the time the customer's next /me or order-fetch
 * round-trip lands. The email-send listener stays queued separately.
 */
class MarkInvoiceAndOrderPaid
{
    public function handle(PaymentReceived $event): void
    {
        try {
            DB::transaction(function () use ($event): void {
                $invoice = $event->invoice;
                if ($invoice->status !== Invoice::STATUS_PAID) {
                    $invoice->status = Invoice::STATUS_PAID;
                    $invoice->save();
                }

                $order = $invoice->order ?? $invoice->loadMissing('order')->order;
                if ($order === null) {
                    return;
                }

                if (in_array($order->status, ['paid', 'confirmed', 'cancelled', 'refunded'], true)) {
                    return;
                }

                $order->status = 'paid';
                $order->save();
            });
        } catch (\Throwable $e) {
            Log::warning('MarkInvoiceAndOrderPaid failed', [
                'error' => $e->getMessage(),
                'payment_id' => $event->payment->id,
                'invoice_id' => $event->invoice->id,
            ]);
        }
    }
}
