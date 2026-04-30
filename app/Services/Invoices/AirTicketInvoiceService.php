<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

class AirTicketInvoiceService
{
    /**
     * @return array{net_price:float,client_price:float,commission_total:float,vat_amount:float,total_with_services:float}
     */
    public function calculatePricing(Invoice $invoice): array
    {
        $totalAmount = (float) ($invoice->total_amount ?? 0);
        $commissionTotal = (float) ($invoice->commission_total ?? 0);
        $additionalServicesPrice = (float) ($invoice->additional_services_price ?? 0);
        $vatAmount = (float) ($invoice->vat_amount ?? 0);

        $netPrice = $commissionTotal > 0 ? ($totalAmount - $commissionTotal) : $totalAmount;
        $clientPrice = (float) ($invoice->client_price ?? $totalAmount);
        $totalWithServices = $clientPrice + $additionalServicesPrice + $vatAmount;

        return [
            'net_price' => round($netPrice, 2),
            'client_price' => round($clientPrice, 2),
            'commission_total' => round($commissionTotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'total_with_services' => round($totalWithServices, 2),
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
