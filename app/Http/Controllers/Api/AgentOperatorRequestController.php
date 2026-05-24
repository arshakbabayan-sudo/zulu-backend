<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentOperatorRequest;
use App\Models\RequestMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.7 — agent → operator request inbox.
 *
 * Operators see requests targeted at them; agents see requests they sent.
 * Super admins see everything. Status flow: open → in_progress → resolved
 * / rejected.
 */
class AgentOperatorRequestController extends Controller
{
    /**
     * GET /api/agent-operator-requests
     * Lists requests visible to the current user. Param `box=inbox|outbox`
     * filters between received and sent.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $companyIds = $user->companies()->pluck('companies.id')->all();
        $box = $request->query('box', 'all');

        $query = AgentOperatorRequest::query()
            ->with([
                'requester:id,name,email',
                'requesterCompany:id,name',
                'targetCompany:id,name',
                'resolvedBy:id,name',
            ])
            ->orderByDesc('created_at');

        // Visibility filter.
        if (! $user->is_super_admin) {
            $query->where(function ($q) use ($user, $companyIds): void {
                $q->where('requester_user_id', $user->id)
                    ->orWhereIn('requester_company_id', $companyIds)
                    ->orWhereIn('target_company_id', $companyIds);
            });
        }

        if ($box === 'inbox') {
            $query->whereIn('target_company_id', $companyIds);
        } elseif ($box === 'outbox') {
            $query->where(function ($q) use ($user, $companyIds): void {
                $q->where('requester_user_id', $user->id)
                    ->orWhereIn('requester_company_id', $companyIds);
            });
        }

        if (in_array($status = (string) $request->query('status'), AgentOperatorRequest::STATUSES, true)) {
            $query->where('status', $status);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn ($r) => $this->serialize($r))->values()->all(),
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
        $row = $this->resolveVisible($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->serialize($row)]);
    }

    /**
     * POST /api/agent-operator-requests
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'target_company_id' => ['required', 'integer', 'exists:companies,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $requesterCompanyId = $user->companies()->pluck('companies.id')->first();

        $row = AgentOperatorRequest::query()->create([
            'requester_user_id' => $user->id,
            'requester_company_id' => $requesterCompanyId,
            'target_company_id' => $validated['target_company_id'],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'status' => 'open',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['requester', 'requesterCompany', 'targetCompany'])),
        ], 201);
    }

    /**
     * PATCH /api/agent-operator-requests/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveVisible($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(AgentOperatorRequest::STATUSES)],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $row->status = $validated['status'];
        $row->resolution_notes = $validated['resolution_notes'] ?? $row->resolution_notes;
        if (in_array($validated['status'], ['resolved', 'rejected'], true)) {
            $row->resolved_by_user_id = $request->user()->id;
            $row->resolved_at = now();
        } else {
            $row->resolved_by_user_id = null;
            $row->resolved_at = null;
        }
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['requester', 'requesterCompany', 'targetCompany', 'resolvedBy'])),
        ]);
    }

    /**
     * GET /api/agent-operator-requests/{id}/messages
     * Returns the thread of replies, oldest → newest.
     */
    public function listMessages(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveVisible($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $messages = RequestMessage::query()
            ->with(['sender:id,name,email,avatar'])
            ->where('request_id', $row->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $messages->map(fn ($m) => $this->serializeMessage($m))->all(),
        ]);
    }

    /**
     * POST /api/agent-operator-requests/{id}/messages
     * Appends a reply to the thread.
     */
    public function storeMessage(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveVisible($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $message = RequestMessage::query()->create([
            'request_id' => $row->id,
            'sender_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        // Posting on a rejected/resolved request re-opens it so the
        // conversation can continue, mirroring CasesController behavior.
        if (in_array($row->status, ['resolved', 'rejected'], true)) {
            $row->status = 'open';
            $row->resolved_by_user_id = null;
            $row->resolved_at = null;
            $row->save();
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeMessage($message->fresh(['sender'])),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function serializeMessage(RequestMessage $m): array
    {
        return [
            'id' => $m->id,
            'request_id' => $m->request_id,
            'body' => $m->body,
            'sender' => $m->sender
                ? [
                    'id' => $m->sender->id,
                    'name' => $m->sender->name,
                    'email' => $m->sender->email,
                    'avatar' => $m->sender->avatar,
                ]
                : null,
            'created_at' => $m->created_at?->toIso8601String(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }

    private function resolveVisible(Request $request, int $id): ?AgentOperatorRequest
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $row = AgentOperatorRequest::query()
            ->with(['requester', 'requesterCompany', 'targetCompany', 'resolvedBy'])
            ->find($id);

        if ($row === null) {
            return null;
        }
        if ($user->is_super_admin) {
            return $row;
        }

        $companyIds = $user->companies()->pluck('companies.id')->all();
        $isParticipant = $row->requester_user_id === $user->id
            || in_array((int) $row->requester_company_id, $companyIds, true)
            || in_array((int) $row->target_company_id, $companyIds, true);

        return $isParticipant ? $row : null;
    }

    /** @return array<string, mixed> */
    private function serialize(AgentOperatorRequest $row): array
    {
        return [
            'id' => $row->id,
            'subject' => $row->subject,
            'body' => $row->body,
            'status' => $row->status,
            'resolution_notes' => $row->resolution_notes,
            'requester' => $row->requester
                ? ['id' => $row->requester->id, 'name' => $row->requester->name, 'email' => $row->requester->email]
                : null,
            'requester_company' => $row->requesterCompany
                ? ['id' => $row->requesterCompany->id, 'name' => $row->requesterCompany->name]
                : null,
            'target_company' => $row->targetCompany
                ? ['id' => $row->targetCompany->id, 'name' => $row->targetCompany->name]
                : null,
            'resolved_by' => $row->resolvedBy
                ? ['id' => $row->resolvedBy->id, 'name' => $row->resolvedBy->name]
                : null,
            'resolved_at' => $row->resolved_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
