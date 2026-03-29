<?php

namespace App\Listeners;

use App\Events\SellerApplicationApproved;
use App\Mail\SellerApplicationApproved as SellerApplicationApprovedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSellerApplicationApprovedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(SellerApplicationApproved $event): void
    {
        $event->application->loadMissing('company');
        $email = $event->application->company?->email;

        if (! $email) {
            Log::warning('Seller application approved email skipped: missing company email', [
                'application_id' => $event->application->id,
                'company_id' => $event->application->company_id,
            ]);

            return;
        }

        Mail::to($email)->queue(new SellerApplicationApprovedMail($event->application));
    }
}
