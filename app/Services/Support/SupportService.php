<?php

namespace App\Services\Support;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupportService
{
    /**
     * @param  array{subject:string,priority:string,initial_message:string,company_id?:int|null}  $data
     */
    public function createTicket(int $userId, array $data): SupportTicket
    {
        $validated = Validator::make($data, [
            'subject' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'string', Rule::in(SupportTicket::PRIORITIES)],
            'initial_message' => ['required', 'string'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ])->validate();

        return DB::transaction(function () use ($userId, $validated): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'user_id' => $userId,
                'company_id' => $validated['company_id'] ?? null,
                'subject' => $validated['subject'],
                'priority' => $validated['priority'],
                'status' => SupportTicket::STATUS_OPEN,
            ]);

            SupportMessage::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $userId,
                'message' => $validated['initial_message'],
                'is_admin_reply' => false,
            ]);

            return $ticket->fresh();
        });
    }

    public function addMessage(int $ticketId, int $userId, string $text, bool $isAdmin = false): SupportMessage
    {
        $ticket = SupportTicket::query()->findOrFail($ticketId);

        $message = SupportMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'message' => $text,
            'is_admin_reply' => $isAdmin,
        ]);

        if ($isAdmin) {
            $ticket->status = SupportTicket::STATUS_PENDING;
            $ticket->save();
        }

        return $message->fresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<string, mixed>
     */
    public function listTickets(?int $companyId = null, array $filters = []): Collection
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min(100, $perPage));

        $query = SupportTicket::query()
            ->with(['user:id,name,email'])
            ->withCount('messages')
            ->orderByDesc('updated_at');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if (! empty($filters['status']) && in_array($filters['status'], SupportTicket::STATUSES, true)) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['priority']) && in_array($filters['priority'], SupportTicket::PRIORITIES, true)) {
            $query->where('priority', (string) $filters['priority']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where('subject', 'like', '%'.$search.'%');
        }

        $tickets = $query->paginate($perPage);

        return collect([
            'data' => collect($tickets->items()),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
                'per_page' => $tickets->perPage(),
            ],
        ]);
    }
}
