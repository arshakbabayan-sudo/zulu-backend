<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class InvoiceService
{
    /**
     * @param  list<int>  $companyIds
     * @param  array{from?:?string,to?:?string,status?:?string}  $filters
     */
    private function buildScopedQuery(array $companyIds, ?int $bookingId, array $filters)
    {
        $q = Invoice::query()
            ->whereHas('order', fn ($q2) => $q2->whereIn('company_id', $companyIds))
            ->when(
                $bookingId !== null,
                fn ($q) => $q->whereHas('order', fn ($q2) => $q2
                    ->where('metadata->legacy_origin', 'booking')
                    ->where('metadata->legacy_booking_id', $bookingId))
            );

        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            // Inclusive end-of-day so date pickers behave intuitively.
            $q->where('created_at', '<=', $filters['to'].' 23:59:59');
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{from?:?string,to?:?string,status?:?string}  $filters
     * @return Collection<int, Invoice>
     */
    public function listForCompanies(array $companyIds, ?int $bookingId = null, array $filters = []): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return $this->buildScopedQuery($companyIds, $bookingId, $filters)->orderBy('id')->get();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{from?:?string,to?:?string,status?:?string}  $filters
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20, ?int $bookingId = null, array $filters = []): LengthAwarePaginator
    {
        if ($companyIds === []) {
            return Invoice::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        return $this->buildScopedQuery($companyIds, $bookingId, $filters)
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{total_amount?:numeric,status?:string}  $data
     */
    public function createForOrder(Order $order, array $data = []): Invoice
    {
        return Invoice::query()->create([
            'order_id' => $order->id,
            'total_amount' => $data['total_amount'] ?? $order->total,
            'currency' => $order->currency,
            'unique_booking_reference' => $data['unique_booking_reference'] ?? $order->order_number,
            'status' => $data['status'] ?? 'pending',
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
