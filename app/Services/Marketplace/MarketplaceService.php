<?php

namespace App\Services\Marketplace;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Offer;
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

    public function createBooking(User $user, Offer $offer): Booking
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
     * @return array{booking: Booking, invoice: \App\Models\Invoice, payment: \App\Models\Payment}
     */
    public function checkoutPaidBooking(Booking $booking): array
    {
        return DB::transaction(function () use ($booking): array {
            $locked = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== Booking::STATUS_PENDING) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Booking cannot be checked out',
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
                    && $lockedFresh->status === Booking::STATUS_CONFIRMED
                ) {
                    return [
                        'booking' => $lockedFresh,
                        'invoice' => $existingInvoice->fresh(),
                        'payment' => $payment->fresh(),
                    ];
                }

                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Already checked out',
                ], 409));
            }

            $invoice = $this->invoiceService->createForBooking($locked, []);
            $payment = $this->paymentService->createForInvoice($invoice, []);
            $this->paymentService->markPaid($payment);
            $invoice = $this->invoiceService->markPaid($invoice->fresh());
            $bookingConfirmed = $this->bookingService->confirm($locked->fresh());

            return [
                'booking' => $bookingConfirmed->load('items'),
                'invoice' => $invoice,
                'payment' => $payment->fresh(),
            ];
        });
    }
}
