<?php

namespace App\Services\Pdf;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfService
{
    public function generate(Invoice $invoice): Response
    {
        $invoice->loadMissing(['booking.items.offer', 'booking.company', 'booking.user']);

        $dueDate = $invoice->created_at?->copy()->addDays(30);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'dueDate' => $dueDate,
        ])
            ->setPaper('a4', 'portrait');

        return $pdf->download('invoice-'.$invoice->id.'.pdf');
    }
}
