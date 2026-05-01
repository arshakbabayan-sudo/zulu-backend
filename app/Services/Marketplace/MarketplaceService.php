<?php

namespace App\Services\Marketplace;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Invoices\InvoiceService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class MarketplaceService
{
    public function __construct(
        private BookingService $bookingService,
        private InvoiceService $invoiceService,
        private PaymentService $paymentService,
    ) {}

    public function createBooking(User $user, Offer $offer): Order
    {
        return $this->bookingService->create(
            [
                'user_id' => $user->id,
                'company_id' => $offer->company_id,
            ],
            [
                [
                    'offer_id' => $offer->id,
                    'price' => (float) $offer->price,
                ],
            ],
        );
    }

    /**
     * @return array{order: Order, invoice: Invoice, payment: Payment}
     */
    public function checkoutPaidBooking(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'pending_payment') {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Order cannot be checked out',
                ], 422));
            }

            $existingInvoice = $locked->invoices()->orderBy('id')->first();
            if ($existingInvoice !== null) {
                $payment = $existingInvoice->payments()->orderBy('id')->first();
                $lockedFresh = $locked->fresh(['items']);
                if (
                    $payment !== null
                    && $payment->status === Payment::STATUS_PAID
                    && $existingInvoice->status === Invoice::STATUS_PAID
                    && $lockedFresh !== null
                    && $lockedFresh->status === 'confirmed'
                ) {
                    return [
                        'order' => $lockedFresh,
                        'invoice' => $existingInvoice->fresh(),
                        'payment' => $payment->fresh(),
                    ];
                }

                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Already checked out',
                ], 409));
            }

            $invoice = $this->invoiceService->createForOrder($locked, []);
            $payment = $this->paymentService->createForInvoice($invoice, []);
            $this->paymentService->markPaid($payment);
            $invoice = $this->invoiceService->markPaid($invoice->fresh());
            $confirmedOrder = $this->bookingService->confirm($locked->fresh());

            return [
                'order' => $confirmedOrder->load('items'),
                'invoice' => $invoice,
                'payment' => $payment->fresh(),
            ];
        });
    }
}
