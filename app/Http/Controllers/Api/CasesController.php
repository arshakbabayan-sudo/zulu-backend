<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminCase;
use App\Models\CaseReply;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Phase 7.10 — cases CRUD + assignment endpoints.
 *
 * Visibility rules:
 * - Super admin / platform admin: see all cases.
 * - Staff: see cases opened by them, assigned to them, or scoped to a
 *   company they belong to.
 */
class CasesController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = AdminCase::query()
            ->with(['assignedTo:id,name', 'openedBy:id,name', 'company:id,name'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at');

        if (! $this->adminAccessService->isPlatformAdmin($user) && ! $user->is_super_admin) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->where(function ($q) use ($user, $companyIds): void {
                $q->where('opened_by_user_id', $user->id)
                    ->orWhere('assigned_to_user_id', $user->id);
                if (! empty($companyIds)) {
                    $q->orWhereIn('company_id', $companyIds);
                }
            });
        }

        if (in_array($status = (string) $request->query('status'), AdminCase::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($priority = (string) $request->query('priority'), AdminCase::PRIORITIES, true)) {
            $query->where('priority', $priority);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to_user_id', (int) $request->query('assigned_to'));
        }
        if ($request->filled('search')) {
            $term = (string) $request->query('search');
            $query->where(function ($q) use ($term): void {
                $q->where('case_number', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%");
            });
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 25)));
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

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['nullable', Rule::in(AdminCase::PRIORITIES)],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $caseNumber = 'C-'.now()->format('Ymd').'-'.strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $priority = $validated['priority'] ?? 'normal';
        $openedAt = now();

        $row = AdminCase::query()->create([
            'case_number' => $caseNumber,
            'company_id' => $validated['company_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => 'open',
            'priority' => $priority,
            'sla_due_at' => AdminCase::slaDeadlineFor($priority, $openedAt),
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'opened_by_user_id' => $user->id,
            'opened_at' => $openedAt,
        ]);

        // Auto-responder — immediately acknowledge receipt to the opener.
        $this->sendReceiptAcknowledgement($row, $user);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['assignedTo', 'openedBy', 'company'])),
        ], 201);
    }

    /**
     * Auto-acknowledge a freshly opened case to its opener, in their language.
     * Best-effort — a notification failure must never block case creation.
     */
    private function sendReceiptAcknowledgement(AdminCase $case, User $user): void
    {
        try {
            $lang = in_array($user->preferred_language, ['hy', 'ru', 'en'], true)
                ? (string) $user->preferred_language
                : 'en';
            $pick = fn (array $m): string => $m[$lang] ?? $m['en'];

            app(NotificationService::class)->create([
                'user_id' => (int) $user->id,
                'type' => 'case_received',
                'title' => $pick([
                    'hy' => "Հայցը ստացվեց՝ #{$case->case_number}",
                    'ru' => "Запрос получен: #{$case->case_number}",
                    'en' => "Request received: #{$case->case_number}",
                ]),
                'message' => $pick([
                    'hy' => 'Շնորհակալություն, հայցդ ստացանք ու հնարավորինս շուտ կպատասխանենք։',
                    'ru' => 'Спасибо, мы получили ваш запрос и ответим как можно скорее.',
                    'en' => 'Thanks — we received your request and will respond as soon as possible.',
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('case receipt acknowledgement failed', [
                'case_id' => $case->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = AdminCase::query()->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(AdminCase::STATUSES)],
            'priority' => ['sometimes', Rule::in(AdminCase::PRIORITIES)],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'closing_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (isset($validated['status'])) {
            if (in_array($validated['status'], ['closed', 'resolved'], true) && $row->closed_at === null) {
                $row->closed_at = now();
            } elseif (! in_array($validated['status'], ['closed', 'resolved'], true)) {
                $row->closed_at = null;
            }
        }

        // Priority change re-anchors the SLA deadline from now (not from
        // opened_at) — a priority bump should give staff a fresh window
        // proportional to the new urgency.
        if (isset($validated['priority']) && $validated['priority'] !== $row->priority) {
            $row->sla_due_at = AdminCase::slaDeadlineFor($validated['priority'], now());
            // Reset escalation flag on priority change so a re-prioritised
            // case isn't permanently marked escalated.
            $row->escalated_at = null;
        }

        $row->fill($validated);
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['assignedTo', 'openedBy', 'company'])),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = AdminCase::query()
            ->with(['assignedTo', 'openedBy', 'company'])
            ->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->serialize($row)]);
    }

    /**
     * List the reply thread for a case. Non-staff callers only see replies
     * with visibility=public; staff (super-admin, platform-admin, or someone
     * assigned/opened the case) see internal notes too.
     */
    public function listReplies(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $case = AdminCase::query()->find($id);
        if ($case === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if (! $this->canViewCase($user, $case)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $query = CaseReply::query()
            ->with(['user:id,name,email'])
            ->where('case_id', $case->id)
            ->orderBy('created_at');

        if (! $this->canSeeInternal($user, $case)) {
            $query->where('visibility', 'public');
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn ($r) => $this->serializeReply($r))->all(),
        ]);
    }

    public function storeReply(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $case = AdminCase::query()->find($id);
        if ($case === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if (! $this->canViewCase($user, $case)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'visibility' => ['nullable', Rule::in(CaseReply::VISIBILITIES)],
            'attachments' => ['nullable', 'array'],
            'attachments.*.name' => ['required_with:attachments', 'string'],
            'attachments.*.url' => ['required_with:attachments', 'string'],
        ]);

        $visibility = $validated['visibility'] ?? 'public';
        if ($visibility === 'internal' && ! $this->canSeeInternal($user, $case)) {
            // Customer trying to post an internal note? Silently downgrade.
            $visibility = 'public';
        }

        $reply = CaseReply::query()->create([
            'case_id' => $case->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
            'visibility' => $visibility,
            'attachments' => $validated['attachments'] ?? null,
        ]);

        // Posting on a fully-closed case re-opens it so the conversation can
        // continue. Otherwise leave status alone.
        if (in_array($case->status, ['closed', 'resolved'], true)) {
            $case->status = 'open';
            $case->closed_at = null;
            $case->save();
        }

        // A1.notify — notify opener + assignee when not the poster.
        // Internal-visibility replies only ping the assignee (customer doesn't
        // see internal notes, so notifying them would surface non-public data).
        $this->notifyReplyParticipants($case, $reply, $user, $visibility);

        return response()->json([
            'success' => true,
            'data' => $this->serializeReply($reply->fresh(['user'])),
        ], 201);
    }

    private function notifyReplyParticipants(
        AdminCase $case,
        CaseReply $reply,
        User $author,
        string $visibility,
    ): void {
        $service = app(NotificationService::class);
        $title = mb_substr($case->title ?? "Case #{$case->id}", 0, 120);
        $excerpt = mb_substr(strip_tags((string) $reply->body), 0, 160);
        $message = "💬 {$title}: {$excerpt}";

        $recipients = [];
        if ($case->opened_by_user_id && $case->opened_by_user_id !== $author->id && $visibility === 'public') {
            $recipients[] = (int) $case->opened_by_user_id;
        }
        if ($case->assigned_to_user_id && $case->assigned_to_user_id !== $author->id) {
            $recipients[] = (int) $case->assigned_to_user_id;
        }

        foreach (array_unique($recipients) as $recipientId) {
            try {
                $service->create([
                    'user_id' => $recipientId,
                    'type' => 'case_reply',
                    'title' => "New reply on case #{$case->id}",
                    'message' => $message,
                ]);
            } catch (\Throwable $e) {
                Log::warning('case_reply notification failed', [
                    'case_id' => $case->id,
                    'recipient_id' => $recipientId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function canViewCase(User $user, AdminCase $case): bool
    {
        if ($this->adminAccessService->isPlatformAdmin($user) || $user->is_super_admin) {
            return true;
        }
        if ($case->opened_by_user_id === $user->id || $case->assigned_to_user_id === $user->id) {
            return true;
        }
        if ($case->company_id !== null) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            if (in_array($case->company_id, $companyIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function canSeeInternal(User $user, AdminCase $case): bool
    {
        if ($this->adminAccessService->isPlatformAdmin($user) || $user->is_super_admin) {
            return true;
        }

        return $case->assigned_to_user_id === $user->id;
    }

    /** @return array<string, mixed> */
    private function serializeReply(CaseReply $reply): array
    {
        return [
            'id' => $reply->id,
            'case_id' => $reply->case_id,
            'user' => $reply->user
                ? ['id' => $reply->user->id, 'name' => $reply->user->name, 'email' => $reply->user->email]
                : null,
            'body' => $reply->body,
            'visibility' => $reply->visibility,
            'attachments' => $reply->attachments,
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(AdminCase $row): array
    {
        return [
            'id' => $row->id,
            'case_number' => $row->case_number,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'title' => $row->title,
            'description' => $row->description,
            'status' => $row->status,
            'priority' => $row->priority,
            'assigned_to' => $row->assignedTo
                ? ['id' => $row->assignedTo->id, 'name' => $row->assignedTo->name]
                : null,
            'opened_by' => $row->openedBy
                ? ['id' => $row->openedBy->id, 'name' => $row->openedBy->name]
                : null,
            'opened_at' => $row->opened_at?->toIso8601String(),
            'closed_at' => $row->closed_at?->toIso8601String(),
            'sla_due_at' => $row->sla_due_at?->toIso8601String(),
            'escalated_at' => $row->escalated_at?->toIso8601String(),
            'sla_remaining_minutes' => $this->slaRemainingMinutes($row),
            'closing_notes' => $row->closing_notes,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function slaRemainingMinutes(AdminCase $row): ?int
    {
        if ($row->sla_due_at === null) {
            return null;
        }
        // Closed/resolved cases don't have an active countdown.
        if (in_array($row->status, ['closed', 'resolved'], true)) {
            return null;
        }

        return (int) now()->diffInMinutes($row->sla_due_at, false);
    }
}
