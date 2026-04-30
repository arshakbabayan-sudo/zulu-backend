<?php

namespace App\Services\Invoices;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\PackageOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class InvoiceService
{
    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, Invoice>
     */
    public function listForCompanies(array $companyIds, ?int $bookingId = null): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Invoice::query()
            ->whereHas('order', fn ($q2) => $q2->whereIn('company_id', $companyIds))
            ->when(
                $bookingId !== null,
                fn ($q) => $q->whereHas('order', fn ($q2) => $q2
                    ->where('metadata->legacy_origin', 'booking')
                    ->where('metadata->legacy_booking_id', $bookingId))
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20, ?int $bookingId = null): LengthAwarePaginator
    {
        if ($companyIds === []) {
            return Invoice::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        return Invoice::query()
            ->whereHas('order', fn ($q2) => $q2->whereIn('company_id', $companyIds))
            ->when(
                $bookingId !== null,
                fn ($q) => $q->whereHas('order', fn ($q2) => $q2
                    ->where('metadata->legacy_origin', 'booking')
                    ->where('metadata->legacy_booking_id', $bookingId))
            )
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{total_amount?:numeric,status?:string}  $data
     */
    public function createForBooking(Booking $booking, array $data = []): Invoice
    {
        return $booking->invoices()->create([
            'order_id' => $booking->mirror_order_id,
            'total_amount' => $data['total_amount'] ?? $booking->total_price,
            'status' => $data['status'] ?? 'pending',
        ]);
    }

    /**
     * @param  array{total_amount?:numeric,currency?:string,unique_booking_reference?:string,status?:string,notes?:string|null}  $data
     */
    public function createForPackageOrder(PackageOrder $packageOrder, array $data = []): Invoice
    {
        return Invoice::query()->create([
            'booking_id' => null,
            'package_order_id' => $packageOrder->id,
            'order_id' => $packageOrder->mirror_order_id,
            'total_amount' => $data['total_amount'] ?? $packageOrder->final_total_snapshot,
            'currency' => $data['currency'] ?? $packageOrder->currency,
            'unique_booking_reference' => $data['unique_booking_reference']
                ?? ('PKG-INV-'.$packageOrder->order_number),
            'status' => $data['status'] ?? Invoice::STATUS_PENDING,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function markIssued(Invoice $invoice): Invoice
    {
        $invoice->status = Invoice::STATUS_ISSUED;
        $invoice->save();

        return $invoice->fresh();
    }

    public function markPaid(Invoice $invoice): Invoice
    {
        $invoice->status = Invoice::STATUS_PAID;
        $invoice->save();

        return $invoice->fresh();
    }

    public function cancel(Invoice $invoice): Invoice
    {
        $invoice->status = Invoice::STATUS_CANCELLED;
        $invoice->save();

        return $invoice->fresh();
    }
}
