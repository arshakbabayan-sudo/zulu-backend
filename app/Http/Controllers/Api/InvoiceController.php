<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Concerns\AuthorizesCommerceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\InvoiceResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Admin\AdminAccessService;
use App\Services\Invoices\InvoiceService;
use App\Services\Pdf\InvoicePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    use AuthorizesCommerceAccess;
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, InvoiceService $invoiceService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'invoices.view');
        $bookingId = $request->filled('booking_id')
            ? (int) $request->query('booking_id')
            : null;

        // Phase 7.6 — date range + status filter
        $filters = [
            'from' => $request->filled('from') ? (string) $request->query('from') : null,
            'to' => $request->filled('to') ? (string) $request->query('to') : null,
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
        ];

        if (! $request->filled('page')) {
            $invoices = $invoiceService->listForCompanies($companyIds, $bookingId, $filters);

            return response()->json([
                'success' => true,
                'data' => InvoiceResource::collection($invoices)->resolve(),
            ]);
        }

        $paginator = $invoiceService->paginateForCompanies(
            $companyIds,
            $this->commerceListPerPage($request),
            $bookingId,
            $filters
        );

        return $this->paginatedCommerceResourceResponse($request, $paginator, InvoiceResource::class);
    }

    /**
     * Phase 7.6 — CSV export of invoices matching the same filters as index().
     * Streams a text/csv response with one row per invoice. Limited to 10 000
     * rows to keep memory bounded; UI nudges the user to narrow the range
     * if the result set is larger.
     */
    public function exportCsv(Request $request, InvoiceService $invoiceService): StreamedResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'invoices.view');

        $filters = [
            'from' => $request->filled('from') ? (string) $request->query('from') : null,
            'to' => $request->filled('to') ? (string) $request->query('to') : null,
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
        ];

        $filename = 'invoices-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($invoiceService, $companyIds, $filters): void {
            $fh = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Armenian/Cyrillic properly.
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, [
                'id',
                'unique_booking_reference',
                'status',
                'total_amount',
                'currency',
                'company_id',
                'company_name',
                'order_id',
                'created_at',
                'issued_at',
                'paid_at',
            ]);

            $invoices = $invoiceService->listForCompanies($companyIds, null, $filters);
            foreach ($invoices->take(10000) as $inv) {
                fputcsv($fh, [
                    $inv->id,
                    $inv->unique_booking_reference,
                    $inv->status,
                    $inv->total_amount,
                    $inv->currency,
                    $inv->order?->company_id,
                    $inv->order?->company?->name,
                    $inv->order_id,
                    $inv->created_at?->toIso8601String(),
                    $inv->issued_at?->toIso8601String(),
                    $inv->paid_at?->toIso8601String() ?? null,
                ]);
            }
            fclose($fh);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Phase 7.3 — Per-X Invoicing aggregate view.
     *
     * Group existing invoices by one of several slicing dimensions and
     * return aggregated totals per group. Pure read-only — no schema
     * changes. Useful for monthly statement prep, by-operator finance
     * reconciliation, etc.
     *
     * Supported `group_by`:
     *   - status   → invoice status (issued / paid / cancelled / pending)
     *   - currency → currency code
     *   - month    → YYYY-MM bucket of issuing_date
     *   - operator → seller company on the linked order
     *
     * Companies are scoped per the regular invoices.view rules so
     * operators only see their own slices.
     */
    public function aggregate(Request $request): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'invoices.view');
        $groupBy = (string) $request->query('group_by', 'status');

        $query = Invoice::query();

        // Apply the same company scoping the list endpoint uses (via orders.company_id).
        if ($companyIds !== null) {
            $query->whereHas('order', function ($q) use ($companyIds): void {
                $q->whereIn('company_id', $companyIds);
            });
        }

        $rows = match ($groupBy) {
            'currency' => $query
                ->selectRaw('COALESCE(currency, \'?\') as bucket, COUNT(*) as invoice_count, SUM(total_amount) as total_sum')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get(),
            'month' => $query
                ->selectRaw('TO_CHAR(issuing_date, \'YYYY-MM\') as bucket, COALESCE(currency, \'?\') as currency, COUNT(*) as invoice_count, SUM(total_amount) as total_sum')
                ->whereNotNull('issuing_date')
                ->groupBy('bucket', 'currency')
                ->orderByDesc('bucket')
                ->get(),
            'operator' => $query
                ->join('orders', 'invoices.order_id', '=', 'orders.id')
                ->leftJoin('companies', 'orders.company_id', '=', 'companies.id')
                ->selectRaw('orders.company_id as bucket, companies.name as label, COALESCE(invoices.currency, \'?\') as currency, COUNT(*) as invoice_count, SUM(invoices.total_amount) as total_sum')
                ->groupBy('orders.company_id', 'companies.name', 'invoices.currency')
                ->orderByDesc('total_sum')
                ->get(),
            default => $query
                ->selectRaw('status as bucket, COALESCE(currency, \'?\') as currency, COUNT(*) as invoice_count, SUM(total_amount) as total_sum')
                ->groupBy('bucket', 'currency')
                ->orderBy('bucket')
                ->get(),
        };

        return response()->json([
            'success' => true,
            'data' => [
                'group_by' => $groupBy,
                'buckets' => $rows->map(fn ($r) => [
                    'bucket' => $r->bucket,
                    'label' => $r->label ?? null,
                    'currency' => $r->currency ?? null,
                    'invoice_count' => (int) $r->invoice_count,
                    'total_sum' => $r->total_sum !== null ? (float) $r->total_sum : 0.0,
                ])->all(),
            ],
        ]);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $companyId = $this->resolveInvoiceCommerceCompanyId($invoice);
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'invoices.view')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => InvoiceResource::make($invoice)->toArray($request),
        ]);
    }

    public function downloadPdf(Request $request, Invoice $invoice, InvoicePdfService $pdfService): Response
    {
        $companyId = $this->resolveInvoiceCommerceCompanyId($invoice);

        if ($companyId === null) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'invoices.view')) {
            return $response;
        }

        try {
            return $pdfService->generate($invoice);
        } catch (\Throwable $e) {
            Log::warning('Invoice PDF generation failed', ['error' => $e->getMessage(), 'invoice_id' => $invoice->id]);

            return response()->json([
                'success' => false,
                'message' => 'PDF generation failed',
            ], 500);
        }
    }

    /**
     * Phase Զ.15 / Item 8 — bulk monthly statement PDF for one operator.
     *
     * GET /api/invoices/statement?company_id=42&month=2026-05
     *
     * Returns a single PDF listing every invoice for that operator in
     * that month (matched against issued_at, falling back to created_at).
     */
    public function statement(Request $request, InvoicePdfService $pdfService): Response
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $companyId = (int) $data['company_id'];
        $month = (string) $data['month'];

        if ($response = $this->ensureCommerceAccess($request, $companyId, 'invoices.view')) {
            return $response;
        }

        $company = \App\Models\Company::query()->findOrFail($companyId);

        try {
            return $pdfService->generateStatement($company, $month);
        } catch (\Throwable $e) {
            Log::warning('Invoice statement PDF failed', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'month' => $month,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Statement generation failed',
            ], 500);
        }
    }

    public function store(Request $request, InvoiceService $invoiceService): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
        ]);

        $order = Order::query()->findOrFail((string) $validated['order_id']);
        $companyId = (int) $order->company_id;

        if ($response = $this->ensureCommerceAccess($request, $companyId, 'invoices.create')) {
            return $response;
        }

        $invoice = $invoiceService->createForOrder($order, []);

        return response()->json([
            'success' => true,
            'data' => InvoiceResource::make($invoice)->toArray($request),
        ]);
    }

    public function issue(Request $request, InvoiceService $invoiceService, Invoice $invoice): JsonResponse
    {
        $companyId = $this->resolveInvoiceCommerceCompanyId($invoice);
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'invoices.issue')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => InvoiceResource::make($invoiceService->markIssued($invoice))->toArray($request),
        ]);
    }

    public function pay(Request $request, InvoiceService $invoiceService, Invoice $invoice): JsonResponse
    {
        $companyId = $this->resolveInvoiceCommerceCompanyId($invoice);
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'invoices.pay')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => InvoiceResource::make($invoiceService->markPaid($invoice))->toArray($request),
        ]);
    }

    public function cancel(Request $request, InvoiceService $invoiceService, Invoice $invoice): JsonResponse
    {
        $companyId = $this->resolveInvoiceCommerceCompanyId($invoice);
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'invoices.cancel')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => InvoiceResource::make($invoiceService->cancel($invoice))->toArray($request),
        ]);
    }

    /**
     * Booking-backed invoices use booking.company_id; order-backed invoices use order.company_id.
     */
    private function resolveInvoiceCommerceCompanyId(Invoice $invoice): ?int
    {
        $invoice->loadMissing('order');

        $fromOrder = $invoice->order?->company_id;

        return $fromOrder !== null ? (int) $fromOrder : null;
    }
}
