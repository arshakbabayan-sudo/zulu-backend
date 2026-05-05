<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SellerWebhookController extends Controller
{
    public function __construct(
        private WebhookService $service,
    ) {}

    private function userCompanyId(Request $request): ?int
    {
        $id = $request->user()->companies()->value('companies.id');

        return $id !== null ? (int) $id : null;
    }

    /** GET /api/seller/webhooks */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->userCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $subs = WebhookSubscription::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $subs]);
    }

    /** POST /api/seller/webhooks */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $companyId = $this->userCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => false, 'message' => 'No company associated with user'], 403);
        }

        $company = Company::query()->findOrFail($companyId);

        try {
            $sub = $this->service->subscribe($company, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Return secret only on creation (one-time view)
        return response()->json([
            'success' => true,
            'data' => $sub->makeVisible('secret'),
        ], 201);
    }

    /** DELETE /api/seller/webhooks/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $this->userCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => false, 'message' => 'No company'], 403);
        }
        $company = Company::query()->findOrFail($companyId);

        if (! $this->service->unsubscribe($company, $id)) {
            return response()->json(['success' => false, 'message' => 'Webhook not found'], 404);
        }

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    /** GET /api/seller/webhooks/{id}/deliveries */
    public function deliveries(Request $request, int $id): JsonResponse
    {
        $companyId = $this->userCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $sub = WebhookSubscription::query()
            ->where('company_id', $companyId)
            ->find($id);

        if ($sub === null) {
            return response()->json(['success' => false, 'message' => 'Webhook not found'], 404);
        }

        $deliveries = $sub->deliveries()->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['success' => true, 'data' => $deliveries]);
    }

    /**
     * POST /api/seller/webhooks/{id}/deliveries/{deliveryId}/retry
     *
     * Manually retry a single failed delivery. The webhook subscription must
     * belong to one of the user's companies. WebhookService::attempt() does
     * the actual HTTP call and updates the delivery row with the new attempt.
     */
    public function retryDelivery(Request $request, int $id, int $deliveryId): JsonResponse
    {
        $companyId = $this->userCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => false, 'message' => 'No company'], 403);
        }

        $sub = WebhookSubscription::query()
            ->where('company_id', $companyId)
            ->find($id);
        if ($sub === null) {
            return response()->json(['success' => false, 'message' => 'Webhook not found'], 404);
        }

        $delivery = WebhookDelivery::query()
            ->where('webhook_subscription_id', $sub->id)
            ->find($deliveryId);
        if ($delivery === null) {
            return response()->json(['success' => false, 'message' => 'Delivery not found'], 404);
        }

        $updated = $this->service->attempt($delivery);

        return response()->json([
            'success' => true,
            'data' => $updated->fresh(),
        ]);
    }

    /** GET /api/webhooks/events — public list of supported events */
    public function supportedEvents(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => WebhookSubscription::SUPPORTED_EVENTS,
        ]);
    }
}
