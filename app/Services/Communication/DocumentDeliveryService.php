<?php

namespace App\Services\Communication;

use App\Mail\DocumentsReadyMail;
use App\Models\Invoice;
use App\Services\Invoices\AirTicketInvoiceService;
use App\Services\Invoices\HotelInvoiceService;
use App\Services\Pdf\InvoicePdfService;
use App\Services\Pdf\VoucherPdfService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentDeliveryService
{
    public function __construct(
        private AirTicketInvoiceService $airTicketInvoiceService,
        private HotelInvoiceService $hotelInvoiceService,
        private InvoicePdfService $invoicePdfService,
        private VoucherPdfService $voucherPdfService
    ) {}

    public function sendPaidDocuments(Invoice $invoice): bool
    {
        $invoice->loadMissing(['booking.user', 'packageOrder.user']);

        $email = $this->resolveClientEmail($invoice);
        if ($email === null) {
            Log::warning('Document delivery skipped: recipient email not found.', [
                'invoice_id' => (int) $invoice->id,
            ]);

            return false;
        }

        $invoicePdfPath = $this->resolveInvoicePdfPath($invoice);
        $voucherPdfPath = $this->resolveVoucherPdfPath($invoice);

        if ($invoicePdfPath === null && $voucherPdfPath === null) {
            Log::warning('Document delivery skipped: no PDF attachments resolved.', [
                'invoice_id' => (int) $invoice->id,
            ]);

            return false;
        }

        Mail::to($email)->send(new DocumentsReadyMail($invoice, $invoicePdfPath, $voucherPdfPath));

        Log::info('Paid documents sent to client.', [
            'invoice_id' => (int) $invoice->id,
            'recipient' => $email,
            'invoice_pdf_path' => $invoicePdfPath,
            'voucher_pdf_path' => $voucherPdfPath,
        ]);

        return true;
    }

    private function resolveClientEmail(Invoice $invoice): ?string
    {
        $email = data_get($invoice, 'user.email')
            ?? data_get($invoice, 'booking.user.email')
            ?? data_get($invoice, 'packageOrder.user.email')
            ?? data_get($invoice, 'booking.customer_email');

        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        return $email !== '' ? $email : null;
    }

    private function resolveInvoicePdfPath(Invoice $invoice): ?string
    {
        $candidates = [
            $invoice->invoice_pdf_path ?? null,
            $invoice->invoice_path ?? null,
            $invoice->download_invoice_path ?? null,
            $invoice->pdf_path ?? null,
        ];

        foreach ($candidates as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (Storage::disk('public')->exists($path) || Storage::disk('local')->exists($path)) {
                return $path;
            }
        }

        return $this->generateInvoicePdfPath($invoice);
    }

    private function resolveVoucherPdfPath(Invoice $invoice): ?string
    {
        $invoiceType = (string) ($invoice->invoice_type ?? '');
        $storedPath = str_contains($invoiceType, 'hotel')
            ? $this->hotelInvoiceService->downloadVoucher($invoice)
            : $this->airTicketInvoiceService->downloadVoucher($invoice);

        if ($storedPath !== '') {
            return $storedPath;
        }

        return $this->generateVoucherPdfPath($invoice);
    }

    private function generateInvoicePdfPath(Invoice $invoice): ?string
    {
        try {
            $response = $this->invoicePdfService->generate($invoice);
            $content = $response->getContent();
            if (! is_string($content) || $content === '') {
                return null;
            }

            $path = 'documents/invoices/invoice-'.$invoice->id.'-'.time().'.pdf';
            Storage::disk('public')->put($path, $content);

            return $path;
        } catch (Throwable $e) {
            Log::warning('PDF generation engine not yet configured, skipping attachment.', [
                'invoice_id' => (int) $invoice->id,
                'document_type' => 'invoice',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function generateVoucherPdfPath(Invoice $invoice): ?string
    {
        $booking = $invoice->booking;
        if ($booking === null) {
            Log::warning('Document delivery skipped: booking missing for voucher generation.', [
                'invoice_id' => (int) $invoice->id,
            ]);

            return null;
        }

        try {
            $response = $this->voucherPdfService->generate($booking);
            $content = $response->getContent();
            if (! is_string($content) || $content === '') {
                return null;
            }

            $path = 'documents/vouchers/voucher-'.$invoice->id.'-'.time().'.pdf';
            Storage::disk('public')->put($path, $content);

            return $path;
        } catch (Throwable $e) {
            Log::warning('PDF generation engine not yet configured, skipping attachment.', [
                'invoice_id' => (int) $invoice->id,
                'document_type' => 'voucher',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
