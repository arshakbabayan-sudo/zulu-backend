<?php

namespace App\Services\Pdf;

use App\Models\Company;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

class InvoicePdfService
{
    public function generate(Invoice $invoice): Response
    {
        $invoice->loadMissing(['order.items', 'order.company', 'order.user']);

        $dueDate = $invoice->created_at?->copy()->addDays(30);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'dueDate' => $dueDate,
        ])
            ->setPaper('a4', 'portrait');

        return $pdf->download('invoice-'.$invoice->id.'.pdf');
    }

    /**
     * Phase Զ.15 / Item 8 — bulk monthly statement.
     *
     * Generates one PDF listing every invoice for a given operator in a
     * given month. Uses the issued_at field (falls back to created_at)
     * for the period filter.
     */
    public function generateStatement(Company $company, string $month): Response
    {
        // Month is "YYYY-MM"; defensive parse.
        try {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            $start = Carbon::now()->startOfMonth();
            $month = $start->format('Y-m');
        }
        $end = $start->copy()->endOfMonth();

        /** @var Collection<int, Invoice> $invoices */
        $invoices = Invoice::query()
            ->whereHas('order', fn ($q) => $q->where('company_id', $company->id))
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('issued_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end): void {
                        $q2->whereNull('issued_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->orderBy('issued_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $totalsByCurrency = $invoices
            ->groupBy('currency')
            ->map(fn ($group) => (float) $group->sum(fn (Invoice $i) => (float) $i->total_amount))
            ->all();

        $pdf = Pdf::loadView('pdf.invoice-statement', [
            'companyName' => $company->name,
            'month' => $month,
            'invoices' => $invoices,
            'invoiceCount' => $invoices->count(),
            'totalsByCurrency' => $totalsByCurrency,
            'generatedAt' => Carbon::now(),
        ])->setPaper('a4', 'portrait');

        $filename = "statement-{$company->id}-{$month}.pdf";

        return $pdf->download($filename);
    }
}
