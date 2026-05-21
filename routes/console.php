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

// Sprint 54 — Daily PostgreSQL backup (PART 32). Writes gzipped SQL to local disk
// at storage/app/backups/db, retains 14 days. For off-site retention configure a
// non-local disk via DB_BACKUP_DISK and re-deploy.
Schedule::command('db:backup --disk='.env('DB_BACKUP_DISK', 'local').' --keep='.env('DB_BACKUP_KEEP', 14))
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

// Sprint 77 — Monthly backup-restore drill (PART 32). Replays the most recent
// gzipped dump into a scratch database (zulu_drill) and verifies row presence.
// Drops the scratch DB at the end. Runs on the 1st of each month at 03:30 UTC.
Schedule::command('db:restore-drill --execute')
    ->monthlyOn(1, '03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Sprint H4 — Daily backup verification. Runs ~1 hour after db:backup so the
// fresh artefact is on disk. Confirms the latest .sql.gz exists, is recent,
// is large enough, and starts with the gzip magic bytes. When FILES_BACKUP_DIR
// is set in env, also verifies the most recent zulu-files-*.tar.gz under it.
// Telegram alert on failure; silent on success.
Schedule::command('backup:verify')
    ->dailyAt('03:35')
    ->withoutOverlapping()
    ->onOneServer();

// Sprint H1 follow-up — Sentry → Telegram bridge.
//   - watch mode every 10 minutes: critical issues + bursts → immediate alert
//   - digest mode daily at 09:00 UTC: morning roll-up across all three projects
// Both no-op when SENTRY_API_TOKEN is unset (dev / CI / pre-token state).
Schedule::command('sentry:poll-issues --mode=watch')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('sentry:poll-issues --mode=digest')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

// Sprint H1 follow-up — Snyk dependency-vulnerability bridge.
//   - watch mode every 6h: alerts when high+critical count rises on any repo
//   - digest mode Mondays 09:30 UTC: weekly H/M/L roll-up
// Snyk auto-opens fix PRs in GitHub; Claude reviews + merges them.
Schedule::command('snyk:poll-issues --mode=watch')
    ->cron('0 */6 * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('snyk:poll-issues --mode=digest')
    ->weeklyOn(1, '09:30')
    ->withoutOverlapping()
    ->onOneServer();

// Sprint 78 — Health check every 10 minutes (PART 31).
// Posts Telegram alerts on DB failure / error rate spike / disk pressure /
// webhook backlog. Cooldown 60 min per condition. No-op when
// TELEGRAM_BOT_TOKEN is not configured.
Schedule::command('health:check --quiet-ok')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Sprint 78 — Daily digest (Telegram) at 09:00 UTC.
Schedule::command('health:check --digest --quiet-ok')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

// Cases — every 15 minutes, escalate cases whose SLA window has elapsed.
Schedule::command('cases:escalate-overdue')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// GDPR — hourly sweep for accounts whose 30-day deletion grace expired.
// Cheap when nothing's due; runs only on the primary scheduler host.
Schedule::command('accounts:purge-expired')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// GDPR — daily sweep of stale data-export ZIPs (7-day download window).
Schedule::command('accounts:purge-expired-data-exports')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();
