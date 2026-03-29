<?php

namespace App\Listeners;

use App\Events\CompanyApplicationRejected;
use App\Mail\CompanyApplicationRejected as CompanyApplicationRejectedMailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCompanyApplicationRejectedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CompanyApplicationRejected $event): void
    {
        try {
            Mail::to($event->application->business_email)
                ->queue(new CompanyApplicationRejectedMailable($event->application));
        } catch (\Throwable $e) {
            Log::warning('Failed to queue company application rejected email', [
                'error' => $e->getMessage(),
                'application_id' => $event->application->id,
            ]);
        }
    }
}
