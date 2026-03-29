<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Invoice $invoice
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
            'invoice_id' => $this->invoice->id,
            'status' => (string) $this->invoice->status,
            'title' => __('notifications.invoice.paid.title'),
            'message' => __('notifications.invoice.paid.message', [
                'reference' => (string) ($this->invoice->unique_booking_reference ?? $this->invoice->id),
                'amount' => (string) $this->invoice->total_amount,
            ]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.invoice.paid.subject'))
            ->line(__('notifications.invoice.paid.message', [
                'reference' => (string) ($this->invoice->unique_booking_reference ?? $this->invoice->id),
                'amount' => (string) $this->invoice->total_amount,
            ]))
            ->line(__('notifications.common.view_dashboard'));
    }
}
