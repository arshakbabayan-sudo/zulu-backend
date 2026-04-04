<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Admin\AdminSupportController;
use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use App\Services\Support\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupportAdminController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccess,
        private CompanyAccessService $companyAccessService
    ) {}

    public function index(Request $request, SupportService $supportService): JsonResponse
    {
        if ($deny = $this->denyUnlessSupportAccess($request)) {
            return $deny;
        }

        $user = $request->user();
        $companyId = $this->resolveSupportCompanyId($request, $user);

        $filters = [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', 20),
        ];

        try {
            $result = $supportService->listTickets($companyId, $filters);
        } catch (\Throwable $e) {
            Log::error('SupportAdminController: failed to list tickets', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load support tickets.',
            ], 500);
        }

        $tickets = $result->get('data', collect())->map(fn (SupportTicket $t) => $this->ticketListPayload($t));

        return response()->json([
            'success' => true,
            'data' => $tickets->values()->all(),
            'meta' => $result->get('meta', []),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessSupportAccess($request)) {
            return $deny;
        }

        $user = $request->user();
        $companyId = $this->resolveSupportCompanyId($request, $user);

        $query = SupportTicket::query()
            ->with([
                'user:id,name,email',
                'messages' => static fn ($q) => $q->orderBy('id'),
                'messages.user:id,name,email',
            ])
            ->whereKey($id);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $ticket = $query->first();
        if ($ticket === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->ticketDetailPayload($ticket),
        ]);
    }

    public function storeMessage(Request $request, int $id, SupportService $supportService): JsonResponse
    {
        if ($deny = $this->denyUnlessSupportAccess($request)) {
            return $deny;
        }

        $user = $request->user();
        $companyId = $this->resolveSupportCompanyId($request, $user);

        $scoped = SupportTicket::query()->whereKey($id);
        if ($companyId !== null) {
            $scoped->where('company_id', $companyId);
        }
        if (! $scoped->exists()) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $message = $supportService->addMessage($id, (int) $user->id, (string) $data['message'], true);
        $message->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'message' => 'Reply sent successfully.',
            'data' => ['message' => $this->messagePayload($message)],
        ]);
    }

    /**
     * Super admins and platform admins may optionally pass ?company_id= to scope results;
     * omitting it means "all tenants" (intentional for platform-class oversight roles only).
     * Operator admins are always scoped to their own first company.
     */
    private function resolveSupportCompanyId(Request $request, User $user): ?int
    {
        if ($this->adminAccess->isSuperAdmin($user) || $this->adminAccess->isPlatformAdmin($user)) {
            $companyId = $request->query('company_id');

            return is_numeric($companyId) ? (int) $companyId : null;
        }

        return $this->companyAccessService->resolveOperatorAdminCompanyId($user);
    }

    private function denyUnlessSupportAccess(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if ($this->adminAccess->isPlatformAdmin($user)) {
            return null;
        }

        if ($this->companyAccessService->resolveOperatorAdminCompanyId($user) === null) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function ticketListPayload(SupportTicket $t): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'status' => $t->status,
            'priority' => $t->priority,
            'company_id' => $t->company_id,
            'messages_count' => $t->messages_count ?? null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'user' => $t->relationLoaded('user') && $t->user !== null
                ? [
                    'id' => $t->user->id,
                    'name' => $t->user->name,
                    'email' => $t->user->email,
                ]
                : null,
        ];
    }

    private function ticketDetailPayload(SupportTicket $t): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'status' => $t->status,
            'priority' => $t->priority,
            'company_id' => $t->company_id,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'user' => $t->relationLoaded('user') && $t->user !== null
                ? [
                    'id' => $t->user->id,
                    'name' => $t->user->name,
                    'email' => $t->user->email,
                ]
                : null,
            'messages' => $t->relationLoaded('messages')
                ? $t->messages->map(fn (SupportMessage $m) => $this->messagePayload($m))->values()->all()
                : [],
        ];
    }

    private function messagePayload(SupportMessage $m): array
    {
        return [
            'id' => $m->id,
            'message' => $m->message,
            'is_admin_reply' => $m->is_admin_reply,
            'created_at' => $m->created_at?->toIso8601String(),
            'user' => $m->relationLoaded('user') && $m->user !== null
                ? [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                ]
                : null,
        ];
    }
}
