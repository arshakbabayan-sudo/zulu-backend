<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Admin\AdminAccessService;
use App\Services\Webhooks\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Platform-admin webhook oversight (Sprint 52, PART 30) + subscription
 * management (roadmap §4, 2026-06-12): create/update/pause/delete from the
 * Settings → Webhooks page. Per-seller self-service stays on
 * SellerWebhookController; both write paths go through WebhookService so the
 * validation can't drift.
 */
class AdminWebhookController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private WebhookService $webhookService,
    ) {}

    /** Non-super staff may only touch subscriptions of companies they can see. */
    private function denyUnlessCompanyInScope(Request $request, int $companyId): ?JsonResponse
    {
        $user = $request->user();
        if ($user !== null && $this->adminAccessService->isSuperAdmin($user)) {
            return null;
        }
        $visible = $user !== null ? ($this->adminAccessService->visibleCompanyIds($user) ?: [0]) : [0];
        if (! in_array($companyId, $visible, true)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /** Stable wire shape for the admin UI (the model's raw attrs leak none of secret). */
    private function subscriptionArray(WebhookSubscription $s): array
    {
        return [
            'id' => $s->id,
            'company_id' => $s->company_id,
            'company' => $s->relationLoaded('company') && $s->company
                ? ['id' => $s->company->id, 'name' => $s->company->name]
                : null,
            'target_url' => $s->target_url,
            'events' => $s->events ?? [],
            'description' => $s->description,
            'active' => (bool) $s->active,
            'failure_count' => (int) $s->failure_count,
            'last_succeeded_at' => optional($s->last_succeeded_at)->toIso8601String(),
            'last_failed_at' => optional($s->last_failed_at)->toIso8601String(),
            'created_at' => optional($s->created_at)->toIso8601String(),
        ];
    }

    /** GET webhooks/events — the catalog the subscription form offers. */
    public function events(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        return response()->json(['success' => true, 'data' => WebhookSubscription::SUPPORTED_EVENTS]);
    }

    /** POST webhooks/subscriptions — create for any in-scope company. */
    public function storeSubscription(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'target_url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        if ($deny = $this->denyUnlessCompanyInScope($request, (int) $validated['company_id'])) {
            return $deny;
        }

        $company = Company::query()->findOrFail((int) $validated['company_id']);

        try {
            $sub = $this->webhookService->subscribe($company, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // The signing secret is shown ONCE, on creation only.
        $payload = $this->subscriptionArray($sub->load('company:id,name'));
        $payload['secret'] = $sub->secret;

        return response()->json(['success' => true, 'data' => $payload], 201);
    }

    /** PATCH webhooks/subscriptions/{id} — edit url/events/description or pause/resume. */
    public function updateSubscription(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $sub = WebhookSubscription::query()->find($id);
        if ($sub === null) {
            return response()->json(['success' => false, 'message' => 'Webhook not found'], 404);
        }
        if ($deny = $this->denyUnlessCompanyInScope($request, (int) $sub->company_id)) {
            return $deny;
        }

        $validated = $request->validate([
            'target_url' => ['sometimes', 'url', 'max:500'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string'],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        try {
            $this->webhookService->update($sub, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->subscriptionArray($sub->fresh('company:id,name'))]);
    }

    /** DELETE webhooks/subscriptions/{id} — soft delete. */
    public function destroySubscription(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $sub = WebhookSubscription::query()->find($id);
        if ($sub === null) {
            return response()->json(['success' => false, 'message' => 'Webhook not found'], 404);
        }
        if ($deny = $this->denyUnlessCompanyInScope($request, (int) $sub->company_id)) {
            return $deny;
        }

        $sub->delete();

        return response()->json(['success' => true]);
    }

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->canAccessAdminPanel($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /**
     * GET /api/platform-admin/webhooks/subscriptions
     *
     * Lists all subscriptions across all companies. Optional filters:
     *   ?company_id=N
     *   ?event=order.paid
     *   ?status=active|paused
     */
    public function subscriptions(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = WebhookSubscription::query()
            ->with('company:id,name')
            ->orderByDesc('created_at');

        // Tenant scope: non-super callers see only their own company's webhooks
        // (mandatory — overrides the optional company_id filter below).
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $query->whereIn('company_id', $this->adminAccessService->visibleCompanyIds($user) ?: [0]);
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->query('company_id'));
        }

        if ($request->filled('event')) {
            $event = (string) $request->query('event');
            $query->whereJsonContains('events', $event);
        }

        if ($request->filled('status')) {
            $isActive = strtolower((string) $request->query('status')) === 'active';
            $query->where('active', $isActive);
        }

        $rows = $query->limit(200)->get()
            ->map(fn (WebhookSubscription $s): array => $this->subscriptionArray($s));

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * GET /api/platform-admin/webhooks/deliveries
     *
     * Cross-subscription deliveries log. Optional filters:
     *   ?subscription_id=N
     *   ?status=pending|success|failed
     *   ?event=order.paid
     */
    public function deliveries(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        $query = WebhookDelivery::query()
            ->with('subscription:id,company_id,url')
            ->orderByDesc('created_at');

        if ($request->filled('subscription_id')) {
            $query->where('subscription_id', (int) $request->query('subscription_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('event')) {
            $query->where('event', (string) $request->query('event'));
        }

        $rows = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/platform-admin/webhooks/deliveries/{id}/replay
     *
     * Resets a failed delivery to pending so the next dispatcher run picks
     * it up again. Resets attempt_count and clears error_message. The
     * existing payload is replayed verbatim — same idempotency_key, so
     * the receiver can deduplicate.
     */
    public function replayDelivery(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $delivery = WebhookDelivery::query()->find($id);
        if ($delivery === null) {
            return response()->json(['success' => false, 'message' => 'Delivery not found'], 404);
        }

        if ($delivery->status !== 'failed') {
            return response()->json([
                'success' => false,
                'message' => "Cannot replay a delivery in status '{$delivery->status}'",
            ], 422);
        }

        $delivery->status = 'pending';
        $delivery->attempt_count = 0;
        $delivery->error_message = null;
        $delivery->http_status = null;
        $delivery->response_excerpt = null;
        $delivery->save();

        return response()->json([
            'success' => true,
            'data' => $delivery,
        ]);
    }

    /**
     * GET /api/platform-admin/webhooks/dead-letter
     *
     * Convenience listing for failed deliveries (the dead-letter view).
     */
    public function deadLetter(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        $query = WebhookDelivery::query()
            ->with('subscription:id,company_id,url')
            ->where('status', 'failed')
            ->orderByDesc('last_attempted_at');

        if ($request->filled('subscription_id')) {
            $query->where('subscription_id', (int) $request->query('subscription_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', (string) $request->query('event'));
        }

        $rows = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/platform-admin/webhooks/stats
     *
     * Quick stats for admin dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $stats = [
            'total_subscriptions' => WebhookSubscription::count(),
            'active_subscriptions' => WebhookSubscription::where('active', true)->count(),
            'deliveries_total' => WebhookDelivery::count(),
            'deliveries_success' => WebhookDelivery::where('status', 'success')->count(),
            'deliveries_failed' => WebhookDelivery::where('status', 'failed')->count(),
            'deliveries_pending' => WebhookDelivery::where('status', 'pending')->count(),
        ];

        $stats['success_rate'] = $stats['deliveries_total'] > 0
            ? round(($stats['deliveries_success'] / $stats['deliveries_total']) * 100, 1)
            : null;

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
