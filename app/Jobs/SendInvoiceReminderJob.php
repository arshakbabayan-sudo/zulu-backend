<?php

namespace App\Jobs;

use App\Mail\InvoiceReminderMail;
use App\Models\Invoice;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Finance group v2 — Phase 2d.
 *
 * Sends an overdue-invoice reminder via:
 *  1. Email to the order's customer (InvoiceReminderMail)
 *  2. In-app notification on the customer's profile (NotificationService)
 *
 * Stamps invoices.last_reminder_sent_at on success.
 *
 * Throttling is enforced at the controller level (Invoice::sendReminder
 * rejects requests when last_reminder_sent_at < 24h ago). The job itself
 * just does the send.
 */
class SendInvoiceReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $invoiceId) {}

    public function handle(NotificationService $notifications): void
    {
        $invoice = Invoice::with(['order.user'])->find($this->invoiceId);
        if ($invoice === null) {
            Log::info('SendInvoiceReminderJob: invoice not found', ['invoice_id' => $this->invoiceId]);
            return;
        }

        $user = $invoice->order?->user;
        if ($user === null || empty($user->email)) {
            Log::info('SendInvoiceReminderJob: no recipient', ['invoice_id' => $this->invoiceId]);
            return;
        }

        // 1) Email
        try {
            Mail::to($user->email)->send(new InvoiceReminderMail($invoice));
        } catch (\Throwable $e) {
            Log::warning('SendInvoiceReminderJob: email send failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 2) In-app notification
        try {
            $notifications->create([
                'user_id' => $user->id,
                'type' => 'reminder',
                'title' => 'Invoice #'.$invoice->id.' — reminder',
                'message' => sprintf(
                    'You have an outstanding invoice of %s %s. Please complete payment to avoid late fees.',
                    number_format((float) $invoice->total_amount, 2),
                    strtoupper($invoice->currency ?? '')
                ),
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->id,
                'priority' => 'high',
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendInvoiceReminderJob: in-app notification failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 3) Stamp the invoice
        $invoice->forceFill(['last_reminder_sent_at' => now()])->save();
    }
}
