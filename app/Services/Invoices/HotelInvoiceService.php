<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

class HotelInvoiceService
{
    /**
     * @return array{room_nights:int|null,avg_daily_rate:float|null}
     */
    public function calculateHotelMetrics(Invoice $invoice): array
    {
        $nights = (int) ($invoice->nights ?? 0);

        $roomNights = $invoice->room_nights !== null
            ? (int) $invoice->room_nights
            : ($nights > 0 ? $nights : null);

        $avgDailyRate = $invoice->avg_daily_rate !== null
            ? (float) $invoice->avg_daily_rate
            : ($nights > 0 ? round(((float) ($invoice->client_price ?? $invoice->total_amount ?? 0)) / $nights, 2) : null);

        return [
            'room_nights' => $roomNights,
            'avg_daily_rate' => $avgDailyRate,
        ];
    }

    public function downloadVoucher(Invoice $invoice): string
    {
        $candidates = [
            $invoice->voucher_path ?? null,
            $invoice->voucher_pdf_path ?? null,
            $invoice->voucher_file_path ?? null,
            $invoice->download_voucher_path ?? null,
        ];

        foreach ($candidates as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (Storage::disk('local')->exists($path) || Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return '';
    }
}
