<?php

namespace App\Services\Pdf;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Finance group v2 — payment receipt PDF generator.
 *
 * Generates a single-page receipt for a paid payment. Used by the "Download
 * receipt" IconButton on the Payments table (paid rows only). Mirrors the
 * pattern of InvoicePdfService so we get consistent header / typography
 * across all finance documents.
 *
 * The receipt includes:
 *  - ZULU brand header (logo + company info from PlatformSetting)
 *  - Payment reference, paid_at, method
 *  - Amount + currency
 *  - Linked invoice + order context (company name, booking ref)
 *  - "Thank you for your payment" footer
 */
class PaymentReceiptPdfService
{
    public function generate(Payment $payment): Response
    {
        $payment->loadMissing([
            'invoice.order.company',
            'invoice.order.user',
        ]);

        $pdf = Pdf::loadView('pdf.payment-receipt', [
            'payment' => $payment,
        ])->setPaper('a4', 'portrait');

        $filename = sprintf(
            'receipt-%d-%s.pdf',
            $payment->id,
            $payment->reference_code ?? $payment->created_at?->format('Y-m-d') ?? 'no-ref',
        );

        return $pdf->download($filename);
    }
}
