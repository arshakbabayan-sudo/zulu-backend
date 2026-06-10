<?php

namespace App\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Finance summary PDF export (roadmap §6).
 *
 * Renders a one-page snapshot of the /platform/finance-summary dashboard:
 * hero numbers (total revenue / commission split platform-agent / pending
 * payments + age), the per-currency breakdown table and — when the caller
 * passes it — the revenue-by-service table. Mirrors the pattern of
 * PaymentReceiptPdfService / InvoicePdfService so all finance documents
 * share the same header / typography.
 *
 * `$summary` is the aggregate array built by
 * FinanceStatsController::summaryV2Data() with an optional
 * `revenue_by_service` key; `$range` is the whitelisted ?range= value.
 */
class FinanceSummaryPdfService
{
    public function generate(array $summary, string $range): Response
    {
        $rangeLabel = match ($range) {
            '7d' => 'Last 7 days',
            '90d' => 'Last 90 days',
            'year' => 'This year',
            default => 'Last 30 days',
        };

        $pdf = Pdf::loadView('pdf.finance-summary', [
            'summary' => $summary,
            'range' => $range,
            'rangeLabel' => $rangeLabel,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = 'finance-summary-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }
}
