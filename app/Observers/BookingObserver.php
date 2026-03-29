<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\Notification as AppNotification;
use App\Models\User;
use App\Notifications\BookingStatusChangedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class BookingObserver
{
    public function created(Booking $booking): void
    {
        $operators = $this->resolveOperatorUsers($booking);
        if ($operators->isEmpty()) {
            return;
        }

        $this->storeInAppNotifications(
            $operators,
            'booking.created',
            __('notifications.booking.created.title'),
            __('notifications.booking.created.message', [
                'reference' => (string) ($booking->unique_booking_reference ?? $booking->id),
            ]),
            $booking
        );
    }

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        $status = (string) $booking->status;
        $operators = $this->resolveOperatorUsers($booking);

        $recipients = $this->mergeWithClient($operators, $booking->user);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::sendNow(
            $recipients,
            new BookingStatusChangedNotification($booking, $status),
            ['mail']
        );

        $this->storeInAppNotifications(
            $recipients,
            'booking.status_changed',
            __('notifications.booking.status_changed.title'),
            __('notifications.booking.status_changed.message', [
                'reference' => (string) ($booking->unique_booking_reference ?? $booking->id),
                'status' => __('notifications.booking.statuses.'.$this->normalizeStatusForTranslation($status)),
            ]),
            $booking
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveOperatorUsers(Booking $booking): Collection
    {
        if ((int) $booking->company_id <= 0) {
            return collect();
        }

        return User::query()
            ->whereHas('memberships', function ($query) use ($booking): void {
                $query->where('company_id', $booking->company_id);
            })
            ->get();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function mergeWithClient(Collection $users, ?User $client): Collection
    {
        if ($client !== null) {
            $users->push($client);
        }

        return $users
            ->filter(fn ($user) => $user instanceof User && $user->email !== null && $user->email !== '')
            ->unique('id')
            ->values();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function storeInAppNotifications(
        Collection $users,
        string $type,
        string $title,
        string $message,
        Booking $booking
    ): void {
        foreach ($users as $user) {
            AppNotification::query()->create([
                'user_id' => (int) $user->id,
                'type' => $type,
                'event_type' => $type,
                'title' => $title,
                'message' => $message,
                'status' => 'unread',
                'subject_type' => 'booking',
                'subject_id' => (int) $booking->id,
                'related_company_id' => (int) $booking->company_id,
                'priority' => 'normal',
            ]);
        }
    }

    private function normalizeStatusForTranslation(string $status): string
    {
        return $status === 'cancelled' ? 'canceled' : $status;
    }
}
