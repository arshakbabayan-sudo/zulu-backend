<?php

namespace App\Services\Notifications;

use App\Mail\GenericNotificationMail;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Localization\LocalizationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        return $notification;
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

    /**
     * Notify every staff member of the company a B2C booking is attributed to —
     * the partner the customer picked (agent_company_id), or the supplying
     * operator (company_id) for a direct purchase — that a new booking was just
     * placed, so the seller can reach out and help close the sale. Surfaces on
     * the admin top-bar bell (in-app only; deliberately no email — would spam
     * every employee on each booking).
     *
     * No-op when: the booking has no attributed company, or it was created BY a
     * member of the selling company (sold_by_user_id set → the seller already
     * knows). Each recipient is best-effort — one failed row never blocks the rest.
     */
    public function notifyCompanyStaffOfBooking(Order $order): void
    {
        // A seller employee placed this booking themselves → they already know.
        if ($order->sold_by_user_id !== null) {
            return;
        }

        $companyId = $order->agent_company_id ?? $order->company_id;
        if ($companyId === null) {
            return;
        }

        $company = Company::query()
            ->with('users:id,name,preferred_language')
            ->find($companyId);
        if ($company === null) {
            return;
        }

        $order->loadMissing('user:id,name');
        $rawName = $order->user?->name;
        $customerName = is_string($rawName) && trim($rawName) !== '' ? trim($rawName) : null;
        $orderNumber = (string) ($order->order_number ?? '');

        foreach ($company->users as $staff) {
            try {
                $copy = $this->bookingPlacedCopy(
                    (string) ($staff->preferred_language ?? ''),
                    $customerName,
                    $orderNumber,
                );

                $this->createForEvent([
                    'user_id' => (int) $staff->id,
                    'event_type' => 'booking.created',
                    'title' => $copy['title'],
                    'message' => $copy['message'],
                    'subject_type' => 'order',
                    'related_company_id' => (int) $companyId,
                    'priority' => 'high',
                    'variables' => [
                        'order_number' => $orderNumber,
                        'user_name' => $customerName ?? '',
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Booking-placed staff notification failed', [
                    'order_id' => $order->id,
                    'staff_user_id' => $staff->id ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Localized in-app copy for the booking.created staff notification, in the
     * recipient's preferred language (Armenian default, + English / Russian).
     * Serves as the fallback when no editable notification template exists yet
     * for the event.
     *
     * @return array{title: string, message: string}
     */
    private function bookingPlacedCopy(string $lang, ?string $customerName, string $orderNumber): array
    {
        $code = strtolower(substr(trim($lang), 0, 2));
        $ref = $orderNumber !== '' ? ' '.$orderNumber : '';

        return match ($code) {
            'ru' => [
                'title' => 'Новое бронирование',
                'message' => ($customerName ?? 'Клиент')
                    .' оформил(а) бронирование'.$ref
                    .'. Свяжитесь с клиентом, чтобы завершить продажу.',
            ],
            'en' => [
                'title' => 'New booking',
                'message' => ($customerName ?? 'A customer')
                    .' placed a booking'.$ref
                    .'. Reach out to the customer to complete the sale.',
            ],
            default => [
                'title' => 'Նոր ամրագրում',
                'message' => ($customerName ?? 'Հաճախորդ')
                    .'ը ամրագրում դրեց'.$ref
                    .'։ Կապ պահիր հաճախորդի հետ՝ վաճառքն ավարտելու համար։',
            ],
        };
    }

    /**
     * Same as createForEvent + dispatches GenericNotificationMail to the user's
     * email address. Used by callers (Order/Payment services) that want both
     * in-app + email for the same event without writing a dedicated Mailable.
     *
     * Events that have a richer dedicated Mailable (e.g., voucher.issued →
     * VoucherIssuedMail) should call createForEvent() and dispatch their own
     * mail separately to avoid double-emailing.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForEventWithEmail(array $data): Notification
    {
        $notification = $this->createForEvent($data);

        $this->dispatchEmailFor($notification);

        return $notification;
    }

    public function dispatchEmailFor(Notification $notification): void
    {
        if (! $this->shouldDispatchEmail($notification)) {
            return;
        }

        $user = User::query()->find($notification->user_id);
        $email = $user?->email;
        if (! is_string($email) || $email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new GenericNotificationMail($notification));
        } catch (\Throwable $e) {
            Log::warning('Generic notification email failed', [
                'notification_id' => $notification->id,
                'event_type' => $notification->event_type,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Two gates:
     *  (1) the event has no dedicated Mailable already covering email
     *      (avoids double-emails for voucher.issued etc.);
     *  (2) the user hasn't opted out of the email channel for this event
     *      via UserNotificationPreference.
     */
    private function shouldDispatchEmail(Notification $notification): bool
    {
        $eventsWithDedicatedMail = [
            'voucher.issued', // VoucherIssuedMail
        ];

        if (in_array((string) $notification->event_type, $eventsWithDedicatedMail, true)) {
            return false;
        }

        return UserNotificationPreference::isEnabled(
            (int) $notification->user_id,
            (string) $notification->event_type,
            'email'
        );
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
