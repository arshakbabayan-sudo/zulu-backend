<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotice;
use App\Services\Admin\AdminAccessService;
use App\Services\Notifications\AdminBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → System notifications — admin notices CUD (roadmap §4).
 *
 * A notice is an authored announcement with an audience selector and optional
 * scheduling. "Send" resolves the audience through AdminBroadcastService (the
 * same machinery as /notifications/bulk-send) and stamps sent_at/sent_count;
 * scheduled notices are picked up by the notices:send-due cron. Everything
 * here is super-admin only — exactly like bulk-send.
 */
class AdminNoticeController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private AdminBroadcastService $broadcastService,
    ) {}

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $isSuper = false;
        try {
            $isSuper = $user !== null && $this->adminAccessService->isSuperAdmin($user);
        } catch (\Throwable $e) {
            $isSuper = false;
        }
        if (! $isSuper) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $rows = AdminNotice::query()
            ->with(['company:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AdminNotice $n): array => $this->noticeArray($n));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $data = $this->validatePayload($request);
        $sendNow = (bool) $request->boolean('send_now');

        $notice = new AdminNotice($data);
        $notice->created_by_user_id = optional($request->user())->id;
        $notice->channels = $this->broadcastService->normaliseChannels($data['channels'] ?? null);
        $notice->save();

        if ($sendNow) {
            if ($deny = $this->sendNotice($notice)) {
                // Keep the saved row (as an editable draft) but surface the
                // empty-audience problem to the caller.
                return $deny;
            }
        }

        return response()->json(['success' => true, 'data' => $this->noticeArray($notice->fresh(['company:id,name', 'creator:id,name']))], 201);
    }

    public function update(Request $request, AdminNotice $notice): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }
        if ($notice->sent_at !== null) {
            return response()->json(['success' => false, 'message' => 'Notice already sent'], 422);
        }

        $data = $this->validatePayload($request, partial: true);
        if (array_key_exists('channels', $data)) {
            $data['channels'] = $this->broadcastService->normaliseChannels($data['channels']);
        }
        $notice->update($data);

        return response()->json(['success' => true, 'data' => $this->noticeArray($notice->fresh(['company:id,name', 'creator:id,name']))]);
    }

    public function sendNow(Request $request, AdminNotice $notice): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }
        if ($notice->sent_at !== null) {
            return response()->json(['success' => false, 'message' => 'Notice already sent'], 422);
        }
        if ($deny = $this->sendNotice($notice)) {
            return $deny;
        }

        return response()->json(['success' => true, 'data' => $this->noticeArray($notice->fresh(['company:id,name', 'creator:id,name']))]);
    }

    public function destroy(Request $request, AdminNotice $notice): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $notice->delete();

        return response()->json(['success' => true]);
    }

    /** Resolve + fan out; stamps sent_at/sent_count. Null on success, 422 on empty audience. */
    private function sendNotice(AdminNotice $notice): ?JsonResponse
    {
        $userIds = $this->broadcastService->resolveRecipients($notice->audience, $notice->company_id);
        if (count($userIds) === 0) {
            return response()->json(['success' => false, 'message' => 'No recipients matched the audience.'], 422);
        }

        $this->broadcastService->dispatch(
            $userIds,
            $this->broadcastService->normaliseChannels($notice->channels),
            $notice->title,
            $notice->message,
            $notice->priority ?? 'normal',
        );

        $notice->forceFill(['sent_at' => now(), 'sent_count' => count($userIds)])->save();

        return null;
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'message' => [$req, 'string', 'max:5000'],
            'type' => [$req, 'string', 'in:'.implode(',', AdminNotice::TYPES)],
            'audience' => [$req, 'string', 'in:'.implode(',', AdminNotice::AUDIENCES)],
            'company_id' => ['nullable', 'integer', 'exists:companies,id', 'required_if:audience,by_company'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:'.implode(',', AdminBroadcastService::CHANNELS)],
            'priority' => ['nullable', 'string', 'in:'.implode(',', AdminNotice::PRIORITIES)],
            'scheduled_for' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function noticeArray(AdminNotice $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'message' => $n->message,
            'type' => $n->type,
            'audience' => $n->audience,
            'company' => $n->company ? ['id' => $n->company->id, 'name' => $n->company->name] : null,
            'channels' => $n->channels ?? ['in_app'],
            'priority' => $n->priority,
            'scheduled_for' => optional($n->scheduled_for)->toIso8601String(),
            'is_active' => $n->is_active,
            'sent_at' => optional($n->sent_at)->toIso8601String(),
            'sent_count' => $n->sent_count,
            'status' => $n->statusLabel(),
            'created_by' => $n->creator ? ['id' => $n->creator->id, 'name' => $n->creator->name] : null,
            'created_at' => optional($n->created_at)->toIso8601String(),
        ];
    }
}
