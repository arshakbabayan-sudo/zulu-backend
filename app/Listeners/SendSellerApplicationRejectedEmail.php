<?php

namespace App\Listeners;

use App\Events\SellerApplicationRejected;
use App\Mail\SellerApplicationRejected as SellerApplicationRejectedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSellerApplicationRejectedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(SellerApplicationRejected $event): void
    {
        $event->application->loadMissing('company');
        $email = $event->application->company?->email;

        if (! $email) {
            Log::warning('Seller application rejected email skipped: missing company email', [
                'application_id' => $event->application->id,
                'company_id' => $event->application->company_id,
            ]);

            return;
        }

        Mail::to($email)->queue(new SellerApplicationRejectedMail($event->application));
    }
}
