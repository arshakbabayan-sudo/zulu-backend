<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\AuditLog;
use App\Models\DataExportRequest;
use App\Models\SavedItem;
use App\Services\Pricing\PriceCalculatorService;
use App\Services\UserAccount\AccountDeletionService;
use App\Services\UserAccount\DataExportService;
use App\Services\UserAccount\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => UserResource::make($request->user())->toArray($request),
        ]);
    }

    /**
     * Phase Զ.15 / Item 15 follow-up — recent activity for the current
     * user. Powers /admin-redesign/profile activity chart + recent list.
     *
     * Returns audit log entries where actor_id = the authenticated user,
     * plus a 29-day daily histogram for the bar chart.
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $limit = max(1, min(50, (int) $request->query('limit', 10)));

        $recent = AuditLog::query()
            ->where('actor_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'category', 'action', 'subject_type', 'subject_id', 'created_at']);

        // 29-day daily histogram for the activity bar chart.
        $since = now()->subDays(28)->startOfDay();
        $byDay = AuditLog::query()
            ->where('actor_id', $user->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("date_trunc('day', created_at) as day, count(*) as c")
            ->groupBy('day')
            ->pluck('c', 'day');

        $histogram = [];
        for ($i = 28; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $key = $day->format('Y-m-d 00:00:00');
            $altKey = $day->format('Y-m-d 00:00:00+00');
            $count = (int) ($byDay->get($key) ?? $byDay->get($altKey) ?? 0);
            $histogram[] = $count;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'recent' => $recent,
                'histogram' => $histogram,
            ],
        ]);
    }

    /**
     * Phase 7.14 — current PIN status (set / not set + last set date).
     * Hash itself is never exposed.
     */
    public function pinStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'is_set' => ! empty($user->pin_hash),
                'set_at' => $user->pin_set_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Phase 7.14 — set or change PIN. Requires current account password.
     * When the user already has a PIN, also requires the current PIN to
     * prove they're the one rotating it.
     */
    public function setPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'current_pin' => ['nullable', 'string', 'min:4', 'max:8'],
            'new_pin' => ['required', 'string', 'min:4', 'max:8', 'regex:/^\d+$/'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['password'], (string) $user->password)) {
            return response()->json(['success' => false, 'message' => 'Incorrect account password'], 422);
        }

        if (! empty($user->pin_hash)) {
            if (empty($validated['current_pin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current PIN required to change an existing PIN',
                ], 422);
            }
            if (! Hash::check($validated['current_pin'], (string) $user->pin_hash)) {
                return response()->json(['success' => false, 'message' => 'Current PIN incorrect'], 422);
            }
        }

        $user->pin_hash = Hash::make($validated['new_pin']);
        $user->pin_set_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'data' => [
                'is_set' => true,
                'set_at' => $user->pin_set_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Phase 7.14 — verify the user's PIN before performing a sensitive
     * action. Returns success on match; never reveals the stored hash.
     */
    public function verifyPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'min:4', 'max:8'],
        ]);

        $user = $request->user();
        if (empty($user->pin_hash)) {
            return response()->json(['success' => false, 'message' => 'PIN not set'], 422);
        }
        if (! Hash::check($validated['pin'], (string) $user->pin_hash)) {
            return response()->json(['success' => false, 'message' => 'PIN incorrect'], 422);
        }

        return response()->json(['success' => true, 'data' => ['verified' => true]]);
    }

    /**
     * Phase 7.14 — clear the PIN. Requires current account password.
     */
    public function clearPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['password'], (string) $user->password)) {
            return response()->json(['success' => false, 'message' => 'Incorrect account password'], 422);
        }

        $user->pin_hash = null;
        $user->pin_set_at = null;
        $user->save();

        return response()->json(['success' => true, 'data' => ['is_set' => false]]);
    }

    public function updateProfile(Request $request, UserAccountService $service): JsonResponse
    {
        $user = $service->updateProfile($request->user(), $request->all());

        return response()->json([
            'success' => true,
            'data' => UserResource::make($user)->toArray($request),
        ]);
    }

    // ─── Sessions (Sanctum personal access tokens) ─────────────────────

    /**
     * GET /api/account/sessions — list the current user's Sanctum tokens.
     * Each row carries enough metadata to render a multi-device session
     * list with a "(this device)" pill on the current token and a revoke
     * button on the others.
     */
    public function listSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $current = $request->user()->currentAccessToken();
        $currentId = $current?->id;

        $data = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'is_current' => $currentId !== null && (int) $t->id === (int) $currentId,
            ])
            ->values()
            ->all();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * DELETE /api/account/sessions/{id} — revoke a personal access token.
     * Returns 422 when the caller tries to revoke their own current token
     * (use sign-out instead).
     */
    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $current = $request->user()->currentAccessToken();
        if ($current !== null && (int) $current->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke the current session. Sign out instead.',
            ], 422);
        }

        $token = $user->tokens()->whereKey($id)->first();
        if ($token === null) {
            return response()->json(['success' => false, 'message' => 'Session not found'], 404);
        }

        $token->delete();

        return response()->json(['success' => true, 'data' => ['revoked_id' => $id]]);
    }

    // ─── Change password ────────────────────────────────────────────────

    /**
     * POST /api/account/change-password — verify current password, hash
     * + persist the new one, then revoke every other Sanctum token so the
     * other devices need to re-authenticate. The current token is kept so
     * the caller's session survives the change.
     *
     * Rate-limit is enforced at the route via `throttle:5,1` (5/min/user).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
                'errors' => ['current_password' => ['Current password is incorrect.']],
            ], 422);
        }

        if (Hash::check($validated['new_password'], (string) $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'New password must be different from the current password.',
                'errors' => ['new_password' => ['New password must be different from the current password.']],
            ], 422);
        }

        $user->password = $validated['new_password']; // hashed via cast
        $user->save();

        // Invalidate all OTHER tokens. Keep current so this session stays
        // authenticated; user signs out / back in on other devices.
        $currentId = $request->user()->currentAccessToken()?->id;
        $query = $user->tokens();
        if ($currentId !== null) {
            $query->where('id', '!=', $currentId);
        }
        $revokedCount = $query->delete();

        return response()->json([
            'success' => true,
            'data' => [
                'revoked_other_sessions' => (int) $revokedCount,
            ],
        ]);
    }

    public function tripHistory(Request $request, UserAccountService $service): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $page = max(1, (int) $request->query('page', 1));
        $result = $service->getTripHistory($request->user(), $perPage, $page);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function savedItems(Request $request, UserAccountService $service): JsonResponse
    {
        $moduleType = $request->query('module_type') ?: null;
        $items = $service->getSavedItems($request->user(), is_string($moduleType) ? $moduleType : null);

        $data = $items->map(fn ($row) => $this->savedItemPayload($row))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function saveItem(Request $request, UserAccountService $service): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'module_type' => ['required', 'string', Rule::in(SavedItem::MODULE_TYPES)],
        ]);

        $row = $service->saveItem($request->user(), (int) $validated['offer_id'], $validated['module_type']);

        return response()->json([
            'success' => true,
            'data' => $this->savedItemPayload($row),
        ], 201);
    }

    public function removeSavedItem(Request $request, int $item, UserAccountService $service): JsonResponse
    {
        $service->removeSavedItem($request->user(), $item);

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    // ─── GDPR: account deletion ────────────────────────────────────────

    public function requestDeletion(Request $request, AccountDeletionService $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $deletion = $service->requestDeletion(
            $request->user(),
            $data['reason'] ?? null,
            $request->ip(),
            substr((string) $request->userAgent(), 0, 512)
        );

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $deletion->status,
                'sent_to' => $request->user()->email,
            ],
        ], 202);
    }

    public function confirmDeletion(Request $request, AccountDeletionService $service): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        $result = $service->confirmDeletion($data['token']);
        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or already-used token.',
            ], 410);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $result->status,
                'scheduled_for' => optional($result->scheduled_for)->toIso8601String(),
            ],
        ]);
    }

    public function cancelDeletion(Request $request, AccountDeletionService $service): JsonResponse
    {
        // Caller must be the soft-deleted user (we authenticate via email +
        // password or via a recovery link; this endpoint accepts an
        // authenticated session that has access to a pending-deletion row).
        $userId = (int) $request->input('user_id', $request->user()?->id ?? 0);
        if ($userId <= 0) {
            return response()->json(['success' => false, 'message' => 'User not specified.'], 422);
        }

        $result = $service->cancelDeletion($userId);
        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'No active deletion request to cancel.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => ['status' => $result->status],
        ]);
    }

    // ─── GDPR: data export ─────────────────────────────────────────────

    public function requestDataExport(Request $request, DataExportService $service): JsonResponse
    {
        $user = $request->user();

        // If a generation is already in flight for this user, don't kick off
        // a parallel one — let the in-progress job finish.
        $inFlight = DataExportRequest::query()
            ->where('user_id', $user->id)
            ->where('status', DataExportRequest::STATUS_GENERATING)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest('id')
            ->first();

        if ($inFlight) {
            return response()->json([
                'success' => true,
                'data' => ['status' => $inFlight->status],
            ], 202);
        }

        // Otherwise every "Prepare my data export" click regenerates. Old
        // ready exports still work until they expire (7 days) but a fresh
        // archive is created and a fresh email is queued so the user gets
        // a working link in their inbox now.
        $export = $service->generate($user);

        return response()->json([
            'success' => $export->status === DataExportRequest::STATUS_READY,
            'data' => [
                'status' => $export->status,
                'ready_at' => optional($export->ready_at)->toIso8601String(),
                'expires_at' => optional($export->expires_at)->toIso8601String(),
            ],
        ], $export->status === DataExportRequest::STATUS_READY ? 200 : 202);
    }

    public function downloadDataExport(Request $request): BinaryFileResponse|JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        $export = DataExportRequest::query()
            ->where('download_token', $data['token'])
            ->where('status', DataExportRequest::STATUS_READY)
            ->first();

        if (! $export || ! $export->file_path || ! $export->expires_at || $export->expires_at->isPast()) {
            if ($export && $export->expires_at && $export->expires_at->isPast()) {
                $export->update(['status' => DataExportRequest::STATUS_EXPIRED]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Download link expired or invalid.',
            ], 410);
        }

        $absolutePath = Storage::disk('local')->path($export->file_path);
        if (! is_file($absolutePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Export file missing from storage.',
            ], 410);
        }

        $export->update(['downloaded_at' => now()]);

        return response()->download($absolutePath, basename($absolutePath), [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedItemPayload(SavedItem $row): array
    {
        $offer = $row->offer;
        $pricing = $offer === null
            ? null
            : app(PriceCalculatorService::class)->normalizedPrice($offer->price, $offer->currency);

        return [
            'id' => $row->id,
            'offer_id' => $row->offer_id,
            'module_type' => $row->module_type,
            'status' => $row->status,
            'saved_at' => $row->created_at?->toIso8601String(),
            'offer' => $offer === null ? null : [
                'id' => $offer->id,
                'title' => $offer->title,
                'type' => $offer->type,
                'price' => $pricing['calculated_price'],
                'currency' => $offer->currency,
                'base_price' => $pricing['base_price'],
                'calculated_price' => $pricing['calculated_price'],
                'pricing' => $pricing,
                'status' => $offer->status,
            ],
        ];
    }
}
