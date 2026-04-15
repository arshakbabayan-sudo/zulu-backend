<?php

use App\Jobs\ReleaseExpiredHolds;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tokens:prune')->daily();
Schedule::command('offers:prune-orphans')->hourly();
Schedule::command('localization:check-ui-consistency')->dailyAt('02:15');
Schedule::job(new ReleaseExpiredHolds)->everyMinute();
