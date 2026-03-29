<?php

namespace App\Listeners;

use App\Events\CompanyApplicationSubmitted;
use App\Mail\CompanyApplicationReceivedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCompanyApplicationReceivedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CompanyApplicationSubmitted $event): void
    {
        try {
            Mail::to($event->application->business_email)
                ->queue(new CompanyApplicationReceivedMail($event->application));
        } catch (\Throwable $e) {
            Log::warning('Failed to queue company application received email', [
                'error' => $e->getMessage(),
                'application_id' => $event->application->id,
            ]);
        }
    }
}
