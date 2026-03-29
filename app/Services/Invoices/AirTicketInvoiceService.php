<?php

namespace App\Services\Invoices;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Commission;
use App\Services\Finance\CommissionManagementService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AirTicketInvoiceService
{
    public function __construct(
        private CommissionManagementService $commissionManagementService
    ) {}

    /**
     * @param  array{status?:string,payment_type?:string,commission_total?:numeric,additional_services_price?:numeric,vat_amount?:numeric,invoice_type?:string}  $options
     */
    public function generate(Booking $booking, array $options = []): Invoice
    {
        $booking->loadMissing(['items.offer.flight', 'passengers']);

        $invoice = Invoice::query()->firstOrNew(['booking_id' => $booking->id]);

        $netPrice = (float) ($booking->total_price ?? 0);
        $commissionPricing = $this->commissionManagementService->applyCommission(
            $netPrice,
            (int) $booking->company_id,
            Commission::SERVICE_AIR_TICKET
        );
        $commissionTotal = (float) ($options['commission_total'] ?? $commissionPricing['commission_amount']);
        $additionalServicesPrice = (float) ($options['additional_services_price'] ?? 0);
        $vatAmount = (float) ($options['vat_amount'] ?? 0);
        $clientPrice = (float) ($options['client_price'] ?? ($netPrice + $commissionTotal));

        $invoice->total_amount = $netPrice;
        $invoice->status = (string) ($options['status'] ?? $invoice->status ?? Invoice::STATUS_PENDING);
        $invoice->payment_type = (string) ($options['payment_type'] ?? ($invoice->payment_type ?? ''));
        $invoice->invoice_type = (string) ($options['invoice_type'] ?? ($invoice->invoice_type ?? 'air_ticket'));
        $invoice->commission_total = $commissionTotal;
        $invoice->additional_services_price = $additionalServicesPrice;
        $invoice->vat_amount = $vatAmount;

        $invoice->client_price = $clientPrice;
        $invoice->net_price = $netPrice;

        $flight = $booking->items
            ->pluck('offer.flight')
            ->filter()
            ->first();

        if ($flight !== null) {
            $this->setIfColumnExists($invoice, 'departure_date', optional($flight->departure_at)?->toDateString());
            $this->setIfColumnExists($invoice, 'flight_number', $flight->flight_code_internal);
            $this->setIfColumnExists($invoice, 'flight', $flight->flight_code_internal);
            $this->setIfColumnExists($invoice, 'airline', $flight->company?->name ?? null);
            $this->setIfColumnExists($invoice, 'ticket_time_limit', $flight->reservation_deadline_at);
        }

        if ($booking->passengers->isNotEmpty()) {
            $passenger = $booking->passengers->first();
            $fullName = trim((string) ($passenger->full_name ?? ''));
            $this->setIfColumnExists($invoice, 'passenger', $fullName !== '' ? $fullName : null);
        }

        $bookingReference = (string) ($booking->booking_reference ?? $booking->unique_booking_reference ?? '');
        if ($bookingReference !== '') {
            $this->setIfColumnExists($invoice, 'booking_number', $bookingReference);
            if (Schema::hasColumn('invoices', 'unique_booking_reference')) {
                $invoice->unique_booking_reference = $bookingReference;
            }
        }

        $invoice->save();

        return $invoice->fresh();
    }

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

    private function setIfColumnExists(Invoice $invoice, string $column, mixed $value): void
    {
        if (Schema::hasColumn('invoices', $column)) {
            $invoice->{$column} = $value;
        }
    }
}
