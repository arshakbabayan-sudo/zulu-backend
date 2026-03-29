<?php

namespace App\Services\Vouchers;

use App\Models\Booking;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;

class VoucherService
{
    /**
     * Generate a PDF voucher for a given Booking model.
     *
     * @param Booking $booking
     * @return string The relative path to the generated PDF
     */
    public function generatePdf(Booking $booking): string
    {
        // Load relationships if not already loaded
        $booking->load(['passengers', 'items']);

        $data = [
            'booking' => $booking,
            'date' => Carbon::now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.voucher', $data);
        
        $fileName = 'vouchers/voucher_' . ($booking->reference_number ?? $booking->id) . '_' . time() . '.pdf';
        
        // Ensure the directory exists in storage
        Storage::disk('public')->makeDirectory('vouchers');
        
        // Save the PDF to public storage
        Storage::disk('public')->put($fileName, $pdf->output());

        return $fileName;
    }

    /**
     * Get the full URL of the voucher PDF.
     */
    public function getVoucherUrl(string $path): string
    {
        return Storage::disk('public')->url($path);
    }
}
