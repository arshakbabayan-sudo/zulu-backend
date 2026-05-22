<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * H5 — alerts when free disk space on the storage path drops below a
 * configurable threshold (default 10%). Posts to the Telegram deploy bot.
 *
 * Scheduled hourly in routes/console.php.
 */
class AlertLowDiskSpace extends Command
{
    protected $signature = 'alerts:low-disk-space {--threshold=10 : Percent threshold below which to alert}';

    protected $description = 'Telegram alert when free disk space drops below threshold percent';

    public function handle(): int
    {
        $threshold = max(1, min(99, (int) $this->option('threshold')));
        $path = storage_path();

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            $this->warn('Could not read disk space — skipping.');

            return self::FAILURE;
        }

        $percent = (int) round(($free / $total) * 100);
        $this->info("Free disk: {$percent}% ({$this->fmt($free)} / {$this->fmt($total)}). Threshold: {$threshold}%.");

        if ($percent >= $threshold) {
            return self::SUCCESS;
        }

        // Below threshold — send Telegram alert.
        $token = (string) (env('TELEGRAM_BOT_TOKEN') ?? '');
        $chatId = (string) (env('TELEGRAM_CHAT_ID') ?? '');
        if ($token === '' || $chatId === '') {
            Log::warning('alerts:low-disk-space below threshold but Telegram not configured', [
                'percent' => $percent,
                'free_bytes' => $free,
                'total_bytes' => $total,
            ]);
            $this->warn("⚠️  Below threshold ({$percent}%) but no Telegram credentials — logged only.");

            return self::SUCCESS;
        }

        $host = (string) (gethostname() ?: 'unknown-host');
        $message = "⚠️ <b>Low disk space</b> on {$host}\n".
            "Free: {$this->fmt($free)} ({$percent}%)\n".
            "Total: {$this->fmt($total)}\n".
            "Threshold: {$threshold}%\n".
            'Path: '.$path;

        try {
            Http::timeout(10)->asJson()->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                ],
            );
            $this->info('Telegram alert sent.');
        } catch (\Throwable $e) {
            Log::error('alerts:low-disk-space telegram post failed', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Telegram post failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function fmt(float|int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }

        return number_format($b, 2).' '.$units[$i];
    }
}
