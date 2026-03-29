<?php

namespace App\Listeners;

use App\Events\CompanyApplicationApproved;
use App\Mail\CompanyApplicationApproved as CompanyApplicationApprovedMailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCompanyApplicationApprovedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CompanyApplicationApproved $event): void
    {
        try {
            Mail::to($event->application->business_email)
                ->queue(new CompanyApplicationApprovedMailable(
                    $event->application,
                    $event->user,
                    $event->temporaryPassword
                ));
        } catch (\Throwable $e) {
            Log::warning('Failed to queue company application approved email', [
                'error' => $e->getMessage(),
                'application_id' => $event->application->id,
                'user_id' => $event->user->id,
            ]);
        }
    }
}
