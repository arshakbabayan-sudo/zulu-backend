<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Roadmap §4 — customer side of the support chat.
 *
 * One thread per customer (type='customer', no company), created lazily on
 * the first message and reused forever. Platform staff answer from the
 * admin Chat page (ChatController handles their side). Polling transport,
 * same id-cursor contract as the staff chat.
 */
class CustomerChatController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private NotificationService $notificationService,
    ) {}

    /** GET /customer/chat — my support thread summary (null until first message). */
    public function show(Request $request): JsonResponse
    {
        $me = $request->user();
        if ($me === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        if ($deny = $this->denyIfStaff($me)) {
            return $deny;
        }

        $thread = $this->myThread($me);
        if ($thread === null) {
            return response()->json(['success' => true, 'data' => null]);
        }

        return response()->json(['success' => true, 'data' => $this->summary($thread, $me)]);
    }

    /** GET /customer/chat/messages?after_id=N — poll my thread (marks read). */
    public function messages(Request $request): JsonResponse
    {
        $me = $request->user();
        if ($me === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        if ($deny = $this->denyIfStaff($me)) {
            return $deny;
        }

        $thread = $this->myThread($me);
        if ($thread === null) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $afterId = (int) $request->query('after_id', 0);
        $messages = ChatMessage::query()
            ->where('conversation_id', $thread->id)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->with('sender:id,name')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (ChatMessage $m) => $this->serializeMessage($m, $me));

        ChatParticipant::query()
            ->firstOrCreate(['conversation_id' => $thread->id, 'user_id' => $me->id])
            ->update(['last_read_at' => now()]);

        return response()->json(['success' => true, 'data' => $messages]);
    }

    /** POST /customer/chat/messages — { body }; creates my thread on first use. */
    public function send(Request $request): JsonResponse
    {
        $me = $request->user();
        if ($me === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        if ($deny = $this->denyIfStaff($me)) {
            return $deny;
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        // Resolve (or lazily create) the one support thread BEFORE the message
        // transaction. The create is race-safe against the partial unique index
        // on (customer_user_id) WHERE type='customer' — a concurrent first
        // message that lost the race re-fetches the winner's thread.
        [$thread, $isNew] = $this->resolveThread($me);

        $msg = DB::transaction(function () use ($me, $data, $thread) {
            $msg = ChatMessage::query()->create([
                'conversation_id' => $thread->id,
                'sender_id' => $me->id,
                'body' => $data['body'],
            ]);
            ChatConversation::query()->where('id', $thread->id)->update(['last_message_at' => now()]);
            ChatParticipant::query()
                ->firstOrCreate(['conversation_id' => $thread->id, 'user_id' => $me->id])
                ->update(['last_read_at' => now()]);

            return $msg;
        });

        if ($isNew) {
            $this->notifyPlatformStaff($me, $data['body']);
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeMessage($msg, $me),
        ], 201);
    }

    /**
     * The support widget is for B2C customers only. Anyone who can reach the
     * admin panel — platform staff or a member with a role-bound company
     * membership (operator/agent) — must NOT open a customer thread, or their
     * self-thread would pollute the platform support queue. This is the
     * authoritative gate; the widget also hides itself for staff client-side.
     */
    private function denyIfStaff(User $me): ?JsonResponse
    {
        if ($this->adminAccessService->canAccessAdminPanel($me)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /**
     * Find the customer's single support thread, creating it on first use.
     * Race-safe: if two concurrent first messages both try to create, the
     * partial unique index makes the loser throw and we re-fetch the winner.
     *
     * @return array{0: ChatConversation, 1: bool} [thread, isNew]
     */
    private function resolveThread(User $me): array
    {
        $thread = $this->myThread($me);
        if ($thread !== null) {
            return [$thread, false];
        }

        try {
            $thread = ChatConversation::query()->create([
                'company_id' => null,
                'type' => ChatConversation::TYPE_CUSTOMER,
                'title' => null,
                'created_by_user_id' => $me->id,
                'customer_user_id' => $me->id,
                'last_message_at' => now(),
            ]);
            ChatParticipant::query()->firstOrCreate([
                'conversation_id' => $thread->id,
                'user_id' => $me->id,
            ]);

            return [$thread, true];
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent first message won — reuse its thread, don't re-notify.
            return [$this->myThread($me) ?? throw $e, false];
        }
    }

    private function myThread(User $me): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('type', ChatConversation::TYPE_CUSTOMER)
            ->where('customer_user_id', $me->id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function summary(ChatConversation $thread, User $me): array
    {
        $myPart = ChatParticipant::query()
            ->where('conversation_id', $thread->id)
            ->where('user_id', $me->id)
            ->first();

        $unread = ChatMessage::query()
            ->where('conversation_id', $thread->id)
            ->where('sender_id', '!=', $me->id)
            ->when($myPart?->last_read_at, fn ($q) => $q->where('created_at', '>', $myPart->last_read_at))
            ->count();

        return [
            'id' => $thread->id,
            'unread' => $unread,
            'last_message_at' => optional($thread->last_message_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMessage(ChatMessage $m, User $me): array
    {
        return [
            'id' => $m->id,
            'body' => $m->body,
            'sender' => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
            'mine' => $m->sender_id === $me->id,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }

    /**
     * In-app notification to the platform support crew on a NEW thread only.
     * The fan-out recipients each have their own admin language, so the title
     * is resolved per recipient instead of a single hardcoded string.
     */
    private function notifyPlatformStaff(User $customer, string $firstMessage): void
    {
        $titles = [
            'en' => 'New chat from customer: ',
            'hy' => 'Նոր չաթ հաճախորդից՝ ',
            'ru' => 'Новый чат от клиента: ',
        ];

        try {
            $staff = User::query()
                ->whereIn('id', $this->adminAccessService->platformSupportUserIds())
                ->get(['id', 'preferred_language']);

            foreach ($staff as $user) {
                if ($user->id === $customer->id) {
                    continue;
                }
                $lang = in_array($user->preferred_language, ['en', 'hy', 'ru'], true)
                    ? $user->preferred_language
                    : 'hy';
                $this->notificationService->create([
                    'user_id' => $user->id,
                    'type' => 'chat',
                    'title' => $titles[$lang].$customer->name,
                    'message' => mb_substr($firstMessage, 0, 200),
                ]);
            }
        } catch (\Throwable $e) {
            // Notification is best-effort — never block the customer's message.
            Log::warning('Customer chat staff notification failed', ['error' => $e->getMessage()]);
        }
    }
}
