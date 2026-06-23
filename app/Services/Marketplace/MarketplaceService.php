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

    /**
     * Create a single-offer booking.
     *
     * C4 — `$quantity` is the on-screen multiplier the booking page applied
     * to the displayed unit price (pax for flights, nights/units for hotels,
     * participants for excursions, …). It is threaded into the order item so
     * the existing pricing pipeline charges unit_price × quantity, making the
     * server total match the screen total. `$meta` is the free-form booking
     * selection (pax/dates/contact/cabin/notes) recorded on the order.
     * Defaults keep the legacy 2-arg call ({ offer_id }-only) byte-identical.
     *
     * `$priceOverride` (flight cabin pricing) is the RAW selected-cabin
     * adult_price. When set, it is threaded into the item as `price_override`
     * so PricingResolver re-applies the same markup the customer saw on-screen
     * (the cabin's b2c_adult_price) instead of charging offer.price → screen ==
     * charge. Null (no cabin / non-flight) leaves the legacy path unchanged.
     */
    public function createBooking(User $user, Offer $offer, int $quantity = 1, ?array $meta = null, ?float $priceOverride = null, ?string $idempotencyKey = null): Order
    {
        return $this->bookingService->create(
            [
                'user_id' => $user->id,
                'company_id' => $offer->company_id,
                'meta' => $meta,
                // Step 8 — duplicate-order guard. Threaded through to
                // OrderService so it lands on orders.idempotency_key. Null
                // (no header) leaves the legacy behaviour byte-identical.
                'idempotency_key' => $idempotencyKey,
            ],
            [
                [
                    'offer_id' => $offer->id,
                    'price' => (float) $offer->price,
                    'quantity' => $quantity,
                    'price_override' => $priceOverride,
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
