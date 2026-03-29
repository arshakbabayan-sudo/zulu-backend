<?php

namespace App\Listeners;

use App\Events\PaymentReceived;
use App\Mail\PaymentReceivedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentReceivedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PaymentReceived $event): void
    {
        try {
            $invoice = $event->invoice->loadMissing('booking.user', 'packageOrder.user');
            $user = $invoice->booking?->user ?? $invoice->packageOrder?->user;
            if ($user === null) {
                return;
            }

            Mail::to($user->email)
                ->queue(new PaymentReceivedMail($event->payment, $event->invoice));
        } catch (\Throwable $e) {
            Log::warning('Failed to queue payment received email', [
                'error' => $e->getMessage(),
                'payment_id' => $event->payment->id,
            ]);
        }
    }
}
