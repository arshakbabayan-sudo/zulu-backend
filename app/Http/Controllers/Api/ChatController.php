<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Internal chat (2026-06-01, Phase 1 — polling, no websockets).
 *
 * Company-scoped (Arshak option Բ): anyone in the same company can message
 * anyone else in it. Every action is guarded so a user can only touch
 * conversations of a company they belong to and only conversations they
 * participate in. Super-admins are NOT auto-added to company chats — they
 * chat within their own company memberships like everyone else.
 *
 * Roadmap §4: type='customer' threads (B2C customer ↔ platform support) are
 * a PLATFORM queue — visible to super admins / platform staff regardless of
 * participation; the first staff reply joins them as a participant so
 * read-tracking works.
 */
class ChatController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    /** Customer-support threads are a platform queue — who may handle them. */
    private function canHandleCustomerThreads(?User $user): bool
    {
        return $user !== null
            && ($this->adminAccessService->isSuperAdmin($user) || $this->adminAccessService->isPlatformStaff($user));
    }

    private function canAccessConversation(ChatConversation $conversation, User $me): bool
    {
        if ($this->isParticipant($conversation->id, $me->id)) {
            return true;
        }

        return $conversation->type === ChatConversation::TYPE_CUSTOMER && $this->canHandleCustomerThreads($me);
    }
    /** Company ids the caller belongs to (empty => no access). */
    private function myCompanyIds(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }
        try {
            return $user->companies()->pluck('companies.id')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function isParticipant(int $conversationId, int $userId): bool
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->exists();
    }

    /** GET /chat/colleagues?company_id=N — people you can start a chat with. */
    public function colleagues(Request $request): JsonResponse
    {
        $me = $request->user();
        $companyIds = $this->myCompanyIds($request);
        $companyId = (int) ($request->query('company_id') ?: ($companyIds[0] ?? 0));
        if ($companyId === 0 || ! in_array($companyId, $companyIds, true)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $company = \App\Models\Company::query()->with(['users:id,name,email'])->find($companyId);
        $users = $company
            ? $company->users
                ->filter(fn ($u) => $u->id !== ($me?->id))
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values()
            : [];

        return response()->json(['success' => true, 'data' => $users]);
    }

    /** GET /chat/conversations — my conversations, most-recent first. */
    public function conversations(Request $request): JsonResponse
    {
        $me = $request->user();
        if ($me === null) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $convIds = ChatParticipant::query()->where('user_id', $me->id)->pluck('conversation_id');
        $handlesCustomers = $this->canHandleCustomerThreads($me);
        $convs = ChatConversation::query()
            ->where(function ($q) use ($convIds, $handlesCustomers) {
                $q->whereIn('id', $convIds);
                if ($handlesCustomers) {
                    $q->orWhere('type', ChatConversation::TYPE_CUSTOMER);
                }
            })
            ->with(['participants.user:id,name,email', 'customer:id,name,email'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $data = $convs->map(function (ChatConversation $c) use ($me) {
            $myPart = $c->participants->firstWhere('user_id', $me->id);
            $others = $c->participants
                ->filter(fn ($p) => $p->user_id !== $me->id)
                ->map(fn ($p) => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null)
                ->filter()
                ->values();
            // Unread = messages newer than my last_read_at, not sent by me.
            $unread = ChatMessage::query()
                ->where('conversation_id', $c->id)
                ->where('sender_id', '!=', $me->id)
                ->when($myPart?->last_read_at, fn ($q) => $q->where('created_at', '>', $myPart->last_read_at))
                ->count();
            $last = ChatMessage::query()->where('conversation_id', $c->id)->orderByDesc('id')->first();

            $isCustomer = $c->type === ChatConversation::TYPE_CUSTOMER;

            return [
                'id' => $c->id,
                'type' => $c->type,
                'title' => $c->title
                    ?: ($isCustomer ? ($c->customer?->name ?? 'Customer') : $others->pluck('name')->implode(', ')),
                'company_id' => $c->company_id,
                'participants' => $others,
                'is_customer' => $isCustomer,
                'customer' => $isCustomer && $c->customer
                    ? ['id' => $c->customer->id, 'name' => $c->customer->name, 'email' => $c->customer->email]
                    : null,
                'unread' => $unread,
                'last_message' => $last ? ['body' => mb_substr($last->body, 0, 120), 'created_at' => optional($last->created_at)->toIso8601String()] : null,
                'last_message_at' => optional($c->last_message_at)->toIso8601String(),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /chat/conversations — start (or reuse) a direct chat with a
     * colleague, or create a named group. Body: { company_id, user_ids[],
     * title? }. Reuses an existing 1:1 direct conversation if present.
     */
    public function storeConversation(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $companyIds = $this->myCompanyIds($request);
        $companyId = (int) $data['company_id'];
        if (! in_array($companyId, $companyIds, true)) {
            return response()->json(['success' => false, 'message' => 'Not a member of this company'], 403);
        }

        // Everyone in the conversation (me + the targets), unique.
        $memberIds = collect($data['user_ids'])->push($me->id)->unique()->values();

        // Validate the targets are colleagues in the same company.
        $companyUserIds = \App\Models\Company::query()->find($companyId)?->users->pluck('id') ?? collect();
        foreach ($memberIds as $uid) {
            if (! $companyUserIds->contains($uid)) {
                return response()->json(['success' => false, 'message' => 'All participants must belong to the company'], 422);
            }
        }

        $isDirect = $memberIds->count() === 2;

        // Reuse an existing direct thread between exactly these two users.
        if ($isDirect) {
            $existing = ChatConversation::query()
                ->where('company_id', $companyId)
                ->where('type', 'direct')
                ->whereHas('participants', fn ($q) => $q->where('user_id', $memberIds[0]))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $memberIds[1]))
                ->withCount('participants')
                ->get()
                ->firstWhere('participants_count', 2);
            if ($existing) {
                return response()->json(['success' => true, 'data' => ['id' => $existing->id, 'reused' => true]]);
            }
        }

        $conv = DB::transaction(function () use ($companyId, $isDirect, $data, $me, $memberIds) {
            $c = ChatConversation::create([
                'company_id' => $companyId,
                'type' => $isDirect ? 'direct' : 'group',
                'title' => $isDirect ? null : ($data['title'] ?? 'Group chat'),
                'created_by_user_id' => $me->id,
                'last_message_at' => now(),
            ]);
            foreach ($memberIds as $uid) {
                ChatParticipant::create(['conversation_id' => $c->id, 'user_id' => $uid]);
            }
            return $c;
        });

        return response()->json(['success' => true, 'data' => ['id' => $conv->id, 'reused' => false]], 201);
    }

    /** GET /chat/conversations/{conversation}/messages?after_id=N */
    public function messages(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $conv = ChatConversation::query()->find($conversation);
        if ($me === null || $conv === null || ! $this->canAccessConversation($conv, $me)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $afterId = (int) $request->query('after_id', 0);
        $msgs = ChatMessage::query()
            ->where('conversation_id', $conversation)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->with('sender:id,name')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'sender' => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
                'mine' => $m->sender_id === $me->id,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ]);

        // Opening/polling marks the thread read up to now. firstOrCreate:
        // platform staff opening a customer thread join it silently so the
        // unread counter has a row to track.
        ChatParticipant::query()
            ->firstOrCreate(['conversation_id' => $conversation, 'user_id' => $me->id])
            ->update(['last_read_at' => now()]);

        return response()->json(['success' => true, 'data' => $msgs]);
    }

    /** POST /chat/conversations/{conversation}/messages — { body } */
    public function sendMessage(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $conv = ChatConversation::query()->find($conversation);
        if ($me === null || $conv === null || ! $this->canAccessConversation($conv, $me)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $msg = ChatMessage::create([
            'conversation_id' => $conversation,
            'sender_id' => $me->id,
            'body' => $data['body'],
        ]);
        ChatConversation::query()->where('id', $conversation)->update(['last_message_at' => now()]);
        ChatParticipant::query()
            ->firstOrCreate(['conversation_id' => $conversation, 'user_id' => $me->id])
            ->update(['last_read_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $msg->id,
                'body' => $msg->body,
                'sender' => ['id' => $me->id, 'name' => $me->name],
                'mine' => true,
                'created_at' => optional($msg->created_at)->toIso8601String(),
            ],
        ], 201);
    }
}
