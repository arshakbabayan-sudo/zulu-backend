<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Support\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing support ticket controller (Sprint 65, PART 24).
 *
 * Endpoints:
 *   POST /api/support/tickets                       — open ticket
 *   GET  /api/support/tickets                       — my tickets
 *   GET  /api/support/tickets/{id}                  — single (must be mine)
 *   POST /api/support/tickets/{id}/messages         — reply to my own ticket
 */
class CustomerSupportController extends Controller
{
    public function __construct(
        private SupportService $service,
    ) {}

    /** POST /api/support/tickets */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'string', 'in:low,medium,high'],
            'initial_message' => ['required', 'string', 'max:10000'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $ticket = $this->service->createTicket($user->id, $validated);

        return response()->json([
            'success' => true,
            'data' => $ticket->load(['messages']),
        ], 201);
    }

    /** GET /api/support/tickets */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $tickets = SupportTicket::query()
            ->where('user_id', $user->id)
            ->withCount('messages')
            ->latest('id')
            ->get();

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    /** GET /api/support/tickets/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $ticket = SupportTicket::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->with(['messages.user:id,name', 'company:id,name'])
            ->first();

        if ($ticket === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $ticket]);
    }

    /** POST /api/support/tickets/{id}/messages */
    public function addMessage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $ticket = SupportTicket::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($ticket === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (in_array($ticket->status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED], true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot reply to a {$ticket->status} ticket. Open a new ticket instead.",
            ], 422);
        }

        $message = SupportMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => (string) $validated['message'],
            'is_admin_reply' => false,
        ]);

        // Customer reply re-opens a 'pending' ticket back to 'open'
        if ($ticket->status === SupportTicket::STATUS_PENDING) {
            $ticket->update(['status' => SupportTicket::STATUS_OPEN]);
        }

        return response()->json(['success' => true, 'data' => $message]);
    }
}
