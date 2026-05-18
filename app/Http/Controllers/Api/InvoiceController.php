<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\InvoiceResource;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use App\Services\Admin\AdminAccessService;
use App\Services\Invoices\InvoiceService;
use App\Services\Pdf\InvoicePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
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

        if (! $request->filled('page')) {
            $invoices = $invoiceService->listForCompanies($companyIds, $bookingId);

            return response()->json([
                'success' => true,
                'data' => InvoiceResource::collection($invoices)->resolve(),
            ]);
        }

        $paginator = $invoiceService->paginateForCompanies(
            $companyIds,
            $this->commerceListPerPage($request),
            $bookingId
        );

        return $this->paginatedCommerceResourceResponse($request, $paginator, InvoiceResource::class);
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

    private function ensureCommerceAccess(Request $request, int $companyId, string $permission): ?JsonResponse
    {
        if (! $this->adminAccessService->allowsCommerceOperatorAccess($request->user(), $companyId, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
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
