<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\WebhookDelivery;
use App\Services\Monitoring\TelegramAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Production health check (Sprint 78, PART 31).
 *
 * Runs every 10 minutes via the scheduler. Posts Telegram alerts when:
 *   - DB unreachable
 *   - Recent error rate spikes (audit_logs.category=error in the last hour)
 *   - Failed-webhook count grows beyond threshold
 *   - Disk usage on storage path > 85%
 *
 * Per-condition cooldown so the same alert isn't spammed every 10 min.
 * Cooldown state is kept in cache (file driver suffices).
 */
class HealthCheck extends Command
{
    protected $signature = 'health:check
        {--quiet-ok : Do not log when all checks pass}
        {--digest : Send a daily digest summary regardless of state}';

    protected $description = 'Run production health checks and send Telegram alerts on regressions';

    private const COOLDOWN_MINUTES = 60;

    private const ERROR_THRESHOLD_PER_HOUR = 20;

    private const FAILED_WEBHOOK_THRESHOLD = 50;

    private const DISK_USAGE_PCT_THRESHOLD = 85;

    public function handle(TelegramAlertService $alerts): int
    {
        $alertsList = [];
        $digestParts = [];

        // 1. DB reachability
        try {
            DB::connection()->getPdo();
            $digestParts[] = '✅ DB OK';
        } catch (\Throwable $e) {
            $alertsList[] = "🚨 <b>DB unreachable</b>\n<code>".$e->getMessage().'</code>';
            $digestParts[] = '❌ DB unreachable';
        }

        // 2. Recent error rate
        $oneHourAgo = Carbon::now()->subHour();
        $errorCount = AuditLog::query()
            ->where('category', 'error')
            ->where('created_at', '>=', $oneHourAgo)
            ->count();

        if ($errorCount >= self::ERROR_THRESHOLD_PER_HOUR) {
            $alertsList[] = "🚨 <b>Error rate spike</b>\n{$errorCount} errors in the last hour (threshold: ".self::ERROR_THRESHOLD_PER_HOUR.')';
        }
        $digestParts[] = "Errors (1h): {$errorCount}";

        // 3. Failed-webhook backlog
        $failedWebhooks = WebhookDelivery::query()->where('status', 'failed')->count();
        if ($failedWebhooks >= self::FAILED_WEBHOOK_THRESHOLD) {
            $alertsList[] = "⚠️ <b>Webhook backlog</b>\n{$failedWebhooks} deliveries in dead-letter (threshold: ".self::FAILED_WEBHOOK_THRESHOLD.')';
        }
        $digestParts[] = "Failed webhooks: {$failedWebhooks}";

        // 4. Disk usage on storage path
        $storagePath = storage_path();
        $totalBytes = disk_total_space($storagePath);
        $freeBytes = disk_free_space($storagePath);
        if ($totalBytes !== false && $freeBytes !== false && $totalBytes > 0) {
            $usedPct = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1);
            $digestParts[] = "Disk usage: {$usedPct}% ({$this->humanBytes($totalBytes - $freeBytes)} of {$this->humanBytes($totalBytes)})";
            if ($usedPct >= self::DISK_USAGE_PCT_THRESHOLD) {
                $alertsList[] = "🚨 <b>Low disk space</b>\nStorage volume {$usedPct}% used (threshold: ".self::DISK_USAGE_PCT_THRESHOLD.'%)';
            }
        }

        // Send alerts (with cooldown)
        foreach ($alertsList as $message) {
            $key = 'health_alert_'.md5(substr($message, 0, 40));
            if (cache()->has($key)) {
                $this->info('Suppressing repeat alert (cooldown): '.substr($message, 0, 60));

                continue;
            }
            $hostname = gethostname();
            $sent = $alerts->send("🌐 <b>ZULU prod</b> @ {$hostname}\n\n{$message}");
            if ($sent) {
                cache()->put($key, true, now()->addMinutes(self::COOLDOWN_MINUTES));
                $this->warn('ALERT sent: '.substr($message, 0, 80));
            } else {
                $this->warn('ALERT not sent (Telegram disabled?): '.substr($message, 0, 80));
            }
        }

        // Daily digest
        if ($this->option('digest')) {
            $hostname = gethostname();
            $digest = "📊 <b>ZULU prod daily digest</b> @ {$hostname}\n\n".implode("\n", $digestParts);
            $sent = $alerts->send($digest, silent: true);
            if ($sent) {
                $this->info('Digest sent.');
            } else {
                $this->warn('Digest not sent (Telegram disabled?).');
            }
        }

        if ($alertsList === []) {
            if (! $this->option('quiet-ok')) {
                $this->info('All checks passed: '.implode(' | ', $digestParts));
            }

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    private function humanBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, 1).' '.$units[$i];
    }
}
