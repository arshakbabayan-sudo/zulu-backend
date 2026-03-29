<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Services\Localization\LocalizationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class NotificationService
{
    /**
     * @return Collection<int, Notification>
     */
    public function listForUser(int $userId): Collection
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  array{user_id:int,type:string,title:string,message:string,status?:string}  $data
     */
    public function create(array $data): Notification
    {
        if (! isset($data['status'])) {
            $data['status'] = 'unread';
        }

        return Notification::query()->create($data);
    }

    public function markAsRead(Notification $notification): Notification
    {
        $notification->status = 'read';
        $notification->save();

        return $notification->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForEvent(array $data): Notification
    {
        $validated = Validator::make($data, [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'event_type' => ['required', 'string', Rule::in(Notification::EVENT_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'subject_type' => ['nullable', 'string'],
            'subject_id' => ['nullable', 'integer'],
            'related_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'priority' => ['nullable', 'string', Rule::in(Notification::PRIORITIES)],
        ])->validate();

        try {
            $user = User::query()->find($validated['user_id']);
            $localization = app(LocalizationService::class);
            $langCandidate = ($user !== null && $user->preferred_language !== null && $user->preferred_language !== '')
                ? (string) $user->preferred_language
                : (string) config('app.locale', 'en');
            $langCode = $localization->resolveLanguage($langCandidate);
            $template = $localization->getNotificationTemplate(
                (string) $validated['event_type'],
                $langCode,
                'in_app'
            );
            if ($template !== null) {
                $variables = isset($data['variables']) && is_array($data['variables']) ? $data['variables'] : [];
                $rendered = $localization->renderTemplate($template, [
                    'order_number' => (string) ($variables['order_number'] ?? ''),
                    'user_name' => (string) ($variables['user_name'] ?? ''),
                ]);
                $validated['title'] = $rendered['title'];
                $validated['message'] = $rendered['body'];
            }
        } catch (\Throwable) {
            // Keep caller-provided title/message.
        }

        $priority = $validated['priority'] ?? 'normal';

        return Notification::query()->create([
            'user_id' => (int) $validated['user_id'],
            'type' => (string) $validated['event_type'],
            'title' => (string) $validated['title'],
            'message' => (string) $validated['message'],
            'status' => 'unread',
            'event_type' => (string) $validated['event_type'],
            'subject_type' => isset($validated['subject_type']) ? (string) $validated['subject_type'] : null,
            'subject_id' => isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            'related_company_id' => isset($validated['related_company_id']) ? (int) $validated['related_company_id'] : null,
            'priority' => $priority,
        ]);
    }

    public function markAllReadForUser(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('status', 'unread')
            ->update(['status' => 'read']);
    }

    public function getUnreadCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('status', 'unread')
            ->count();
    }

    public function paginateForUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, $perPage);

        return Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
