<?php

namespace Tests\Feature\Sprint1;

use App\Mail\DocumentsReadyMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Communication\DocumentDeliveryService;
use App\Services\Invoices\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDeliveryOrderEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_paid_documents_uses_order_user_email(): void
    {
        Storage::fake('public');
        Mail::fake();

        [$order, $invoice] = $this->createBookingAndInvoice();
        $order = Order::query()->findOrFail($invoice->order_id);
        $originalEmail = $order->user?->email;
        $recipient = $this->createUser('document-delivery-order-recipient-'.str()->uuid().'@example.test');
        $order->user_id = $recipient->id;
        $order->save();

        $invoicePath = 'documents/invoices/invoice-'.$invoice->id.'-fixture.pdf';
        Storage::disk('public')->put($invoicePath, 'invoice-pdf-content');
        $invoice->setAttribute('download_invoice_path', $invoicePath);

        $result = app(DocumentDeliveryService::class)->sendPaidDocuments($invoice);

        $this->assertTrue($result);
        Mail::assertSent(DocumentsReadyMail::class, function (DocumentsReadyMail $mail) use ($recipient): bool {
            return $mail->hasTo($recipient->email);
        });
        $this->assertNotSame($originalEmail, $recipient->email);
    }

    public function test_send_paid_documents_returns_false_when_order_user_email_missing(): void
    {
        Mail::fake();

        [, $invoice] = $this->createBookingAndInvoice();
        $order = Order::query()->findOrFail($invoice->order_id);
        $order->user_id = null;
        $order->save();

        $result = app(DocumentDeliveryService::class)->sendPaidDocuments($invoice->fresh());

        $this->assertFalse($result);
        Mail::assertNothingSent();
    }

    public function test_send_paid_documents_sets_voucher_pdf_path_to_null(): void
    {
        Storage::fake('public');
        Mail::fake();

        [, $invoice] = $this->createBookingAndInvoice();
        $invoicePath = 'documents/invoices/invoice-'.$invoice->id.'-fixture.pdf';
        Storage::disk('public')->put($invoicePath, 'invoice-pdf-content');
        $invoice->setAttribute('download_invoice_path', $invoicePath);

        $result = app(DocumentDeliveryService::class)->sendPaidDocuments($invoice);

        $this->assertTrue($result);
        Mail::assertSent(DocumentsReadyMail::class, function (DocumentsReadyMail $mail): bool {
            return $mail->voucherPdfPath === null;
        });
    }

    /**
     * @return array{Order, Invoice}
     */
    private function createBookingAndInvoice(): array
    {
        $company = $this->createCompany();
        $user = $this->createUser('document-delivery-booking-user-'.str()->uuid().'@example.test');
        $offer = $this->createOffer($company, 100.00);
        $order = app(BookingService::class)->create(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => 'pending_payment',
                'currency' => 'USD',
            ],
            [
                ['offer_id' => $offer->id, 'price' => 100.00],
            ],
            []
        );

        $invoice = app(InvoiceService::class)->createForOrder($order, ['total_amount' => 100]);

        return [$order->fresh(['user']), $invoice->fresh()];
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Document Delivery Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Document Delivery User',
            'email' => $email,
            'password' => 'password',
        ]);
    }

    private function createOffer(Company $company, float $price): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Document Delivery Offer '.str()->uuid(),
            'price' => $price,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
    }
}
