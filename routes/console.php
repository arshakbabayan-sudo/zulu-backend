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

// Sprint 8 — Cart hold release sweep (PART 22)
Schedule::command('cart:release-expired-holds')->everyFiveMinutes()->withoutOverlapping();

// Sprint 5 — Audit log integrity verification (PART 26)
Schedule::command('audit:verify-integrity')->dailyAt('03:00')->withoutOverlapping();

// Sprint 17 — Weekly i18n coverage report (Mondays at 04:30)
Schedule::command('i18n:audit')->weeklyOn(1, '04:30');

// Sprint 21 — OpenAPI spec regeneration (daily at 04:00; written to storage/app/openapi.json)
Schedule::command('api:generate-openapi --output=storage/app/openapi.json')->dailyAt('04:00');
