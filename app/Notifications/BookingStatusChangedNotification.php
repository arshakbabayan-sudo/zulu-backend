<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $newStatus
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'status' => $this->newStatus,
            'title' => __('notifications.booking.status_changed.title'),
            'message' => __('notifications.booking.status_changed.message', [
                'reference' => (string) ($this->booking->unique_booking_reference ?? $this->booking->id),
                'status' => __('notifications.booking.statuses.'.$this->normalizeStatusForTranslation($this->newStatus)),
            ]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.booking.status_changed.subject'))
            ->line(__('notifications.booking.status_changed.message', [
                'reference' => (string) ($this->booking->unique_booking_reference ?? $this->booking->id),
                'status' => __('notifications.booking.statuses.'.$this->normalizeStatusForTranslation($this->newStatus)),
            ]))
            ->line(__('notifications.common.view_dashboard'));
    }

    private function normalizeStatusForTranslation(string $status): string
    {
        return $status === 'cancelled' ? 'canceled' : $status;
    }
}
