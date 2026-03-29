<?php

namespace App\Services\Invoices;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\Invoice;
use App\Services\Finance\CommissionManagementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class HotelInvoiceService
{
    public function __construct(
        private CommissionManagementService $commissionManagementService
    ) {}

    /**
     * @param  array{
     *     status?:string,
     *     payment_type?:string,
     *     commission_total?:numeric,
     *     additional_services_price?:numeric,
     *     vat_amount?:numeric,
     *     invoice_type?:string,
     *     check_in?:string,
     *     check_out?:string,
     *     promo_code?:string,
     *     supplier_id?:string,
     *     order_source?:string,
     *     hotel_order_id?:string,
     *     rate_name?:string,
     *     hotel_line?:string
     * }  $options
     */
    public function generate(Booking $booking, array $options = []): Invoice
    {
        $booking->loadMissing(['items.offer.hotel.rooms.pricings', 'passengers', 'user']);

        $invoice = Invoice::query()->firstOrNew(['booking_id' => $booking->id]);

        $netPrice = (float) ($booking->total_price ?? 0);
        $commissionPricing = $this->commissionManagementService->applyCommission(
            $netPrice,
            (int) $booking->company_id,
            Commission::SERVICE_HOTEL
        );
        $commissionTotal = (float) ($options['commission_total'] ?? $commissionPricing['commission_amount']);
        $additionalServicesPrice = (float) ($options['additional_services_price'] ?? 0);
        $vatAmount = (float) ($options['vat_amount'] ?? 0);
        $clientPrice = (float) ($options['client_price'] ?? ($netPrice + $commissionTotal));

        $invoice->total_amount = $netPrice;
        $invoice->status = (string) ($options['status'] ?? $invoice->status ?? Invoice::STATUS_PENDING);
        $invoice->payment_type = (string) ($options['payment_type'] ?? ($invoice->payment_type ?? ''));
        $invoice->invoice_type = (string) ($options['invoice_type'] ?? 'hotel');
        $invoice->commission_total = $commissionTotal;
        $invoice->additional_services_price = $additionalServicesPrice;
        $invoice->vat_amount = $vatAmount;
        $invoice->client_price = $clientPrice;
        $invoice->net_price = $netPrice;

        $bookingReference = (string) ($booking->booking_reference ?? $booking->unique_booking_reference ?? '');
        if ($bookingReference !== '' && Schema::hasColumn('invoices', 'unique_booking_reference')) {
            $invoice->unique_booking_reference = $bookingReference;
        }

        $this->setIfColumnExists($invoice, 'promo_code', $options['promo_code'] ?? ($invoice->promo_code ?? null));
        $this->setIfColumnExists($invoice, 'order_source', $options['order_source'] ?? ($invoice->order_source ?? null));
        $this->setIfColumnExists($invoice, 'supplier_id', $options['supplier_id'] ?? ($invoice->supplier_id ?? null));
        $this->setIfColumnExists($invoice, 'hotel_order_id', $options['hotel_order_id'] ?? ($invoice->hotel_order_id ?? null));
        $this->setIfColumnExists($invoice, 'rate_name', $options['rate_name'] ?? ($invoice->rate_name ?? null));
        $this->setIfColumnExists($invoice, 'hotel_line', $options['hotel_line'] ?? ($invoice->hotel_line ?? null));

        $offer = $booking->items
            ->pluck('offer')
            ->filter(fn ($item) => $item?->type === 'hotel')
            ->first();

        $hotel = $offer?->hotel;
        $room = $hotel?->rooms?->first();
        $pricing = $room?->pricings?->first();

        $checkIn = $options['check_in'] ?? $pricing?->valid_from?->toDateString();
        $checkOut = $options['check_out'] ?? $pricing?->valid_to?->toDateString();

        $this->setIfColumnExists($invoice, 'check_in', $checkIn);
        $this->setIfColumnExists($invoice, 'check_out', $checkOut);
        $this->setIfColumnExists($invoice, 'hotel_name', $hotel?->hotel_name);
        $this->setIfColumnExists($invoice, 'room_type', $room?->room_type);
        $this->setIfColumnExists($invoice, 'meal_plan', $hotel?->meal_type);

        if ($booking->user !== null) {
            $this->setIfColumnExists($invoice, 'user_email', $booking->user->email);
        }

        if ($booking->passengers->isNotEmpty()) {
            $fullNames = $booking->passengers
                ->map(fn ($passenger) => trim((string) ($passenger->full_name ?? '')))
                ->filter()
                ->values();

            $this->setIfColumnExists($invoice, 'guest_names', $fullNames->implode(', '));
            $this->setIfColumnExists($invoice, 'adults_count', $booking->passengers->where('passenger_type', 'adult')->count());
            $this->setIfColumnExists($invoice, 'children_count', $booking->passengers->where('passenger_type', 'child')->count());
        }

        if ($checkIn !== null && $checkOut !== null) {
            try {
                $nights = Carbon::parse($checkIn)->startOfDay()->diffInDays(Carbon::parse($checkOut)->startOfDay(), false);
                $this->setIfColumnExists($invoice, 'nights', $nights > 0 ? $nights : null);
            } catch (\Throwable) {
                $this->setIfColumnExists($invoice, 'nights', null);
            }
        }

        $invoice->save();

        $metrics = $this->calculateHotelMetrics($invoice);
        $this->setIfColumnExists($invoice, 'room_nights', $metrics['room_nights']);
        $this->setIfColumnExists($invoice, 'avg_daily_rate', $metrics['avg_daily_rate']);
        $invoice->save();

        return $invoice->fresh();
    }

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

    private function setIfColumnExists(Invoice $invoice, string $column, mixed $value): void
    {
        if (Schema::hasColumn('invoices', $column)) {
            $invoice->{$column} = $value;
        }
    }
}
