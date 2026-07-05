<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Social\MetaMessengerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read side of the social inbox (Facebook Messenger / Instagram Direct):
 * list conversations and their messages so the CRM can display them.
 *
 * Scope mirrors the CRM: a super-admin sees every page's conversations; a
 * company user sees only their company's (page→company mapping is set later —
 * until then conversations carry a null company_id and are super-only, which
 * is correct for the single-operator rollout).
 *
 * Sending replies lives in a follow-up (needs the page access token).
 */
class SocialInboxController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $access,
        private readonly MetaMessengerService $messenger,
    ) {
    }

    /** GET crm/social/conversations — inbox list, newest activity first. */
    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        $query = SocialConversation::query()
            ->with(['lead:id,name,status'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }

        $rows = $query->limit(200)->get()->map(function (SocialConversation $c): array {
            $last = $c->messages()->latest('id')->first();

            return [
                'id' => $c->id,
                'channel' => $c->channel,
                'psid' => $c->psid,
                'customer_name' => $c->customer_name,
                'lead' => $c->lead ? ['id' => $c->lead->id, 'name' => $c->lead->name, 'status' => $c->lead->status] : null,
                'unread_count' => $c->unread_count,
                'last_message_at' => optional($c->last_message_at)->toIso8601String(),
                'last_preview' => $last?->text !== null && $last?->text !== ''
                    ? mb_strimwidth((string) $last->text, 0, 80, '…')
                    : ($last && ! empty($last->attachments) ? '📎' : ''),
                'last_direction' => $last?->direction,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    /** GET crm/social/conversations/{conversation}/messages — full thread (marks read). */
    public function messages(Request $request, SocialConversation $conversation): JsonResponse
    {
        if (! $this->canSee($request, $conversation)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Opening the thread clears the unread badge.
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        $messages = $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(fn ($m): array => [
                'id' => $m->id,
                'direction' => $m->direction,
                'text' => $m->text,
                'attachments' => $m->attachments,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'channel' => $conversation->channel,
                'psid' => $conversation->psid,
                'customer_name' => $conversation->customer_name,
                'messages' => $messages,
            ],
        ]);
    }

    /** POST crm/social/conversations/{conversation}/read — clear unread badge. */
    public function markRead(Request $request, SocialConversation $conversation): JsonResponse
    {
        if (! $this->canSee($request, $conversation)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $conversation->forceFill(['unread_count' => 0])->save();

        return response()->json(['success' => true]);
    }

    /**
     * POST crm/social/conversations/{conversation}/reply — send a staff reply
     * out to the customer's Messenger and record it as an outbound message.
     */
    public function reply(Request $request, SocialConversation $conversation): JsonResponse
    {
        if (! $this->canSee($request, $conversation)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        $text = trim($data['text']);
        if ($text === '') {
            return response()->json(['message' => 'Empty reply'], 422);
        }

        $sent = $this->messenger->sendText($conversation->psid, $text);
        if (! ($sent['success'] ?? false)) {
            return response()->json([
                'message' => $sent['error'] ?? 'Failed to send',
            ], 422);
        }

        $now = Carbon::now();
        $message = SocialMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => SocialMessage::DIRECTION_OUT,
            'external_message_id' => ($sent['message_id'] ?? '') ?: null,
            'sender_psid' => null,
            'text' => $text,
            'sent_by_user_id' => optional($request->user())->id,
        ]);
        $conversation->forceFill(['last_message_at' => $now])->save();

        return response()->json([
            'data' => [
                'id' => $message->id,
                'direction' => $message->direction,
                'text' => $message->text,
                'attachments' => null,
                'created_at' => optional($message->created_at)->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * A null-company conversation is super-only; a company-scoped one is visible
     * to members of that company (and super).
     */
    private function canSee(Request $request, SocialConversation $conversation): bool
    {
        $companyIds = $this->scopeCompanyIds($request);
        if ($companyIds === null) {
            return true; // super-admin
        }
        if ($conversation->company_id === null) {
            return false;
        }

        return in_array($conversation->company_id, $companyIds, true);
    }

    /**
     * null = all companies (super-admin); otherwise the caller's company ids
     * (['0'] sentinel matches nothing for a company-less / unauthenticated user).
     *
     * @return array<int, int>|null
     */
    private function scopeCompanyIds(Request $request): ?array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return [0];
        }
        try {
            if ($this->access->isSuperAdmin($user)) {
                return null;
            }
        } catch (\Throwable $e) {
            // fall through to company scoping
        }
        try {
            $ids = $user->companies()->pluck('companies.id')->all();
        } catch (\Throwable $e) {
            $ids = [];
        }

        return $ids ?: [0];
    }
}
