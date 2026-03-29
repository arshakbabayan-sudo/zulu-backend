<?php

namespace App\Jobs;

use App\Models\ServiceHold;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredHolds implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $count = ServiceHold::query()
            ->where('released', false)
            ->where('expires_at', '<', now())
            ->update(['released' => true]);

        Log::info("Released {$count} expired service holds.");
    }
}

