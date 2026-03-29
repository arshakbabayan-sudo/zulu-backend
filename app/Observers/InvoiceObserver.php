<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\Notification as AppNotification;
use App\Models\User;
use App\Notifications\InvoicePaidNotification;
use App\Services\Communication\DocumentDeliveryService;
use App\Services\Finance\BonusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        if ((string) $invoice->status !== Invoice::STATUS_PAID) {
            return;
        }

        try {
            app(BonusService::class)->calculateAndRecordBonus($invoice);
        } catch (\Throwable $e) {
            Log::warning('Bonus calculation failed on paid invoice.', [
                'invoice_id' => (int) $invoice->id,
                'message' => $e->getMessage(),
            ]);
        }

        $recipients = $this->resolveRecipients($invoice);
        if (! $recipients->isEmpty()) {
            Notification::sendNow(
                $recipients,
                new InvoicePaidNotification($invoice),
                ['mail']
            );
        }

        $documentsSent = app(DocumentDeliveryService::class)->sendPaidDocuments($invoice);
        $this->appendDeliveryNote($invoice, $documentsSent);
        Log::info('Invoice paid document delivery processed.', [
            'invoice_id' => (int) $invoice->id,
            'documents_sent' => $documentsSent,
        ]);

        if (! $recipients->isEmpty()) {
            $this->storeInAppNotifications(
                $recipients,
                'invoice.paid',
                __('notifications.invoice.paid.title'),
                __('notifications.invoice.paid.message', [
                    'reference' => (string) ($invoice->unique_booking_reference ?? $invoice->id),
                    'amount' => (string) $invoice->total_amount,
                ]),
                $invoice
            );
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(Invoice $invoice): Collection
    {
        $companyId = (int) ($invoice->booking?->company_id ?? $invoice->packageOrder?->company_id ?? 0);
        $operators = $companyId > 0
            ? User::query()
                ->whereHas('memberships', function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId);
                })
                ->get()
            : collect();

        $client = $invoice->booking?->user ?? $invoice->packageOrder?->user;
        if ($client !== null) {
            $operators->push($client);
        }

        return $operators
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
        Invoice $invoice
    ): void {
        $companyId = (int) ($invoice->booking?->company_id ?? $invoice->packageOrder?->company_id ?? 0);

        foreach ($users as $user) {
            AppNotification::query()->create([
                'user_id' => (int) $user->id,
                'type' => $type,
                'event_type' => $type,
                'title' => $title,
                'message' => $message,
                'status' => 'unread',
                'subject_type' => 'invoice',
                'subject_id' => (int) $invoice->id,
                'related_company_id' => $companyId > 0 ? $companyId : null,
                'priority' => 'normal',
            ]);
        }
    }

    private function appendDeliveryNote(Invoice $invoice, bool $sent): void
    {
        $stamp = now()->toDateTimeString();
        $line = $sent
            ? "[{$stamp}] Paid documents delivery: sent."
            : "[{$stamp}] Paid documents delivery: skipped/failed.";

        $existing = trim((string) ($invoice->notes ?? ''));
        $invoice->notes = $existing === '' ? $line : ($existing.PHP_EOL.$line);
        $invoice->saveQuietly();
    }
}
