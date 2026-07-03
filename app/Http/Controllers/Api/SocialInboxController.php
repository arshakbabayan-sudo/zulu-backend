<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        if ($user === null) {
            return [0];
        }
        if ((bool) $user->is_super_admin === true) {
            return null;
        }
        try {
            $ids = $user->companies()->pluck('companies.id')->all();
        } catch (\Throwable $e) {
            $ids = [];
        }

        return $ids ?: [0];
    }
}
