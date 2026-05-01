<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
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
