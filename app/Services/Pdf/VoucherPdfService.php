<?php

namespace App\Services\Pdf;

use App\Models\Booking;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class VoucherPdfService
{
    public function generate(Booking $booking): Response
    {
        $booking->loadMissing(['items.offer.flight', 'company', 'user', 'passengers']);

        $orderCode = 'ZL'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT);

        $flightItem = $booking->items->first(function ($item) {
            $offer = $item->offer;

            return $offer !== null && $offer->type === 'flight' && $offer->flight !== null;
        });

        $logoDataUri = null;
        $company = $booking->company;
        if ($company?->logo) {
            $path = public_path('storage/'.$company->logo);
            if (is_file($path)) {
                $mime = @mime_content_type($path) ?: 'image/png';
                $logoDataUri = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        $pdf = Pdf::loadView('pdf.voucher', [
            'booking' => $booking,
            'orderCode' => $orderCode,
            'flightItem' => $flightItem,
            'logoDataUri' => $logoDataUri,
        ])
            ->setPaper('a4', 'portrait');

        return $pdf->download('voucher-'.$orderCode.'.pdf');
    }
}
