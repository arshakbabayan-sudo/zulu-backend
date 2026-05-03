<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\VisaApplication;
use App\Services\Admin\AdminAccessService;
use App\Services\Modules\VisaApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin visa-application review queue (Sprint 63, PART 16).
 *
 * Endpoints:
 *   GET   /platform-admin/visa-applications              — paginated queue
 *   GET   /platform-admin/visa-applications/{id}         — single detail
 *   POST  /platform-admin/visa-applications/{id}/decide  — approve/reject/processing
 *   GET   /platform-admin/visa-applications/stats        — by-status counters
 */
class AdminVisaApplicationController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private VisaApplicationService $service,
    ) {}

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = VisaApplication::query()
            ->with(['user:id,name,email', 'visa:id,country_id,country,name,visa_type,price'])
            ->latest('id');

        if (is_string($status = $request->query('status')) && in_array($status, ['pending', 'processing', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        if ($visaId = $request->query('visa_id')) {
            $query->where('visa_id', (int) $visaId);
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $application = VisaApplication::query()
            ->with(['user:id,name,email,phone', 'visa'])
            ->find($id);

        if ($application === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $application]);
    }

    /**
     * POST /platform-admin/visa-applications/{id}/decide
     *
     * Body: { status: pending|processing|approved|rejected, note?: string }
     *
     * Persists the new status, appends a note, and emits an in-app notification
     * to the applicant on terminal transitions (approved / rejected).
     */
    public function decide(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'processing', 'approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $application = VisaApplication::query()->find($id);
        if ($application === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $previousStatus = $application->status;
        $this->service->updateStatus($id, (string) $validated['status'], $validated['note'] ?? null);
        $application->refresh();

        // Notify the applicant on status change.
        if ($previousStatus !== $application->status) {
            $this->notifyApplicant($application, $previousStatus);
        }

        return response()->json([
            'success' => true,
            'data' => $application->load(['user:id,name,email', 'visa:id,name,country']),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $byStatus = VisaApplication::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => VisaApplication::query()->count(),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'processing' => (int) ($byStatus['processing'] ?? 0),
                'approved' => (int) ($byStatus['approved'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
                'by_status' => $byStatus,
            ],
        ]);
    }

    private function notifyApplicant(VisaApplication $application, string $previousStatus): void
    {
        $eventMap = [
            'approved' => 'visa.application.approved',
            'rejected' => 'visa.application.rejected',
            'processing' => 'visa.application.processing',
        ];
        $event = $eventMap[$application->status] ?? null;
        if ($event === null) {
            return;
        }

        $titleMap = [
            'approved' => 'Your visa application has been approved',
            'rejected' => 'Your visa application has been rejected',
            'processing' => 'Your visa application is now under review',
        ];
        $messageMap = [
            'approved' => 'Congratulations — your visa application has been approved.',
            'rejected' => 'Unfortunately, your visa application has been rejected.',
            'processing' => 'Your visa application is being reviewed by our team.',
        ];

        try {
            Notification::query()->create([
                'user_id' => $application->user_id,
                'type' => 'visa_application',
                'title' => $titleMap[$application->status] ?? 'Visa application status updated',
                'message' => ($messageMap[$application->status] ?? 'Status changed.')
                    .($application->admin_notes ? "\n\nNote: {$application->admin_notes}" : ''),
                'status' => 'unread',
                'event_type' => $event,
                'subject_type' => VisaApplication::class,
                'subject_id' => (string) $application->id,
                'priority' => $application->status === 'rejected' ? 'high' : 'normal',
            ]);
        } catch (\Throwable $e) {
            logger()->warning('Failed to create visa notification', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
