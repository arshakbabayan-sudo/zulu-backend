<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Notifications\NotificationService;
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

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $thread = $this->myThread($me);
        $isNew = $thread === null;

        $msg = DB::transaction(function () use ($me, $data, &$thread) {
            if ($thread === null) {
                $thread = ChatConversation::query()->create([
                    'company_id' => null,
                    'type' => ChatConversation::TYPE_CUSTOMER,
                    'title' => null,
                    'created_by_user_id' => $me->id,
                    'customer_user_id' => $me->id,
                    'last_message_at' => now(),
                ]);
                ChatParticipant::query()->create([
                    'conversation_id' => $thread->id,
                    'user_id' => $me->id,
                ]);
            }

            $msg = ChatMessage::query()->create([
                'conversation_id' => $thread->id,
                'sender_id' => $me->id,
                'body' => $data['body'],
            ]);
            ChatConversation::query()->where('id', $thread->id)->update(['last_message_at' => now()]);
            ChatParticipant::query()
                ->where('conversation_id', $thread->id)
                ->where('user_id', $me->id)
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

    /** In-app notification to the platform support crew on a NEW thread only. */
    private function notifyPlatformStaff(User $customer, string $firstMessage): void
    {
        try {
            foreach ($this->adminAccessService->platformSupportUserIds() as $userId) {
                if ($userId === $customer->id) {
                    continue;
                }
                $this->notificationService->create([
                    'user_id' => $userId,
                    'type' => 'chat',
                    'title' => 'Նոր չաթ հաճախորդից՝ '.$customer->name,
                    'message' => mb_substr($firstMessage, 0, 200),
                ]);
            }
        } catch (\Throwable $e) {
            // Notification is best-effort — never block the customer's message.
            Log::warning('Customer chat staff notification failed', ['error' => $e->getMessage()]);
        }
    }
}
