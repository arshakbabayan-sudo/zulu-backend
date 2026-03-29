<?php

namespace App\Services\Payments;

use App\Events\PaymentReceived;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, Payment>
     */
    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Payment::query()
            ->where(function ($q) use ($companyIds): void {
                $q->whereHas('invoice.booking', function ($query) use ($companyIds): void {
                    $query->whereIn('company_id', $companyIds);
                })
                    ->orWhereHas('invoice.packageOrder', function ($query) use ($companyIds): void {
                        $query->whereIn('company_id', $companyIds);
                    });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        if ($companyIds === []) {
            return Payment::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        return Payment::query()
            ->where(function ($q) use ($companyIds): void {
                $q->whereHas('invoice.booking', function ($query) use ($companyIds): void {
                    $query->whereIn('company_id', $companyIds);
                })
                    ->orWhereHas('invoice.packageOrder', function ($query) use ($companyIds): void {
                        $query->whereIn('company_id', $companyIds);
                    });
            })
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{amount?:numeric,status?:string,payment_method?:string|null}  $data
     */
    public function createForInvoice(Invoice $invoice, array $data = []): Payment
    {
        return $invoice->payments()->create([
            'amount' => $data['amount'] ?? $invoice->total_amount,
            'status' => $data['status'] ?? 'pending',
            'payment_method' => $data['payment_method'] ?? null,
        ]);
    }

    /**
     * @param  array{amount?:numeric,currency?:string,payment_method?:string|null,reference_code?:string|null,status?:string,notes?:string|null}  $data
     */
    public function createForPackageOrderInvoice(Invoice $invoice, array $data = []): Payment
    {
        return $invoice->payments()->create([
            'amount' => $data['amount'] ?? $invoice->total_amount,
            'currency' => $data['currency'] ?? $invoice->currency,
            'status' => $data['status'] ?? Payment::STATUS_PENDING,
            'payment_method' => $data['payment_method'] ?? null,
            'reference_code' => $data['reference_code'] ?? null,
            'paid_at' => null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function markPaid(Payment $payment): Payment
    {
        $payment->status = Payment::STATUS_PAID;
        $payment->paid_at = now();
        $payment->save();

        $fresh = $payment->fresh(['invoice']);

        try {
            $invoice = $fresh?->invoice;
            if ($invoice !== null) {
                event(new PaymentReceived($fresh, $invoice));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch payment received event', ['error' => $e->getMessage()]);
        }

        return $fresh;
    }

    public function markFailed(Payment $payment): Payment
    {
        $payment->status = Payment::STATUS_FAILED;
        $payment->save();

        return $payment->fresh();
    }

    public function refund(Payment $payment): Payment
    {
        $payment->status = Payment::STATUS_REFUNDED;
        $payment->save();

        return $payment->fresh();
    }
}
