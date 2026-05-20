<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deep health-check endpoint for external uptime monitors.
 *
 *   GET /api/health/deep
 *
 *   200 OK when every probe succeeds:
 *     { status: "ok", version, checks: { db, cache, storage, migrations }, latency_ms: { ... } }
 *
 *   503 Service Unavailable when one or more probes fail. Error messages
 *   are intentionally NOT included in the response body — `status: "down"`
 *   on a probe is the only signal external monitors get, since detailed
 *   exception text on a public endpoint risks information disclosure.
 *
 * The basic Laravel `/up` route stays as the liveness probe — this
 * endpoint is for readiness / deeper health.
 *
 * Roadmap ref: H3 in docs/roadmaps/zulu-roadmap-2026-05-20.md.
 */
class HealthController extends Controller
{
    public function deep(): JsonResponse
    {
        $checks = [];
        $latency = [];

        // 1. Database
        [$ok, $ms] = $this->time(static function (): void {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
        });
        $checks['db'] = $ok ? 'ok' : 'down';
        $latency['db_ms'] = $ms;

        // 2. Cache store (`CACHE_STORE` driver — could be database, redis, file).
        // Round-trips a known key so a misconfigured driver fails the probe.
        [$ok, $ms] = $this->time(static function (): void {
            $probeKey = 'health:probe:'.bin2hex(random_bytes(4));
            Cache::put($probeKey, '1', 5);
            $read = Cache::get($probeKey);
            Cache::forget($probeKey);
            if ($read !== '1') {
                throw new \RuntimeException('cache round-trip mismatch');
            }
        });
        $checks['cache'] = $ok ? 'ok' : 'down';
        $latency['cache_ms'] = $ms;

        // 3. Storage disk (default — `FILESYSTEM_DISK`). Probes write+delete on
        // a tmp path so a read-only mount or full disk fails the probe.
        [$ok, $ms] = $this->time(static function (): void {
            $probePath = 'health-probe-'.bin2hex(random_bytes(4)).'.txt';
            Storage::disk('local')->put($probePath, 'ok');
            $contents = Storage::disk('local')->get($probePath);
            Storage::disk('local')->delete($probePath);
            if ($contents !== 'ok') {
                throw new \RuntimeException('storage round-trip mismatch');
            }
        });
        $checks['storage'] = $ok ? 'ok' : 'down';
        $latency['storage_ms'] = $ms;

        // 4. Pending migrations — non-zero means a deploy ran without
        // `php artisan migrate`, which usually leads to runtime errors.
        $pendingCount = 0;
        $migrationsOk = true;
        try {
            // `migrate:status --pending` exits 1 when there are pending
            // migrations; capture rather than throw.
            Artisan::call('migrate:status', ['--pending' => true]);
            $output = Artisan::output();
            // Each pending migration is one row in the output table; count
            // lines that look like migration names (contain "20" date prefix).
            $pendingCount = (int) preg_match_all('/^\s*\|\s*Pending\s*\|\s*\d{4}_\d{2}_\d{2}/m', $output);
        } catch (Throwable) {
            $migrationsOk = false;
        }
        $checks['migrations'] = $migrationsOk
            ? ($pendingCount === 0 ? 'ok' : 'pending')
            : 'down';

        $allOk = ! in_array('down', $checks, true);

        $body = [
            'status' => $allOk ? 'ok' : 'down',
            'version' => $this->resolveVersion(),
            'checks' => $checks,
            'latency_ms' => $latency,
            'pending_migrations' => $pendingCount,
            'time' => now()->toIso8601String(),
        ];

        return response()->json($body, $allOk ? 200 : 503);
    }

    /**
     * Wraps a callable, returns [ok, elapsed_ms]. Swallows errors —
     * the caller maps `ok=false` to `down` without leaking the message.
     *
     * @return array{0: bool, 1: int}
     */
    private function time(callable $probe): array
    {
        $start = hrtime(true);
        try {
            $probe();
            $ok = true;
        } catch (Throwable) {
            $ok = false;
        }
        $ms = (int) round((hrtime(true) - $start) / 1_000_000);

        return [$ok, $ms];
    }

    private function resolveVersion(): string
    {
        // Prefer an explicit env (set by GH Actions to the short SHA).
        $env = (string) (env('APP_VERSION') ?? '');
        if ($env !== '') {
            return $env;
        }

        // Fallback: try reading .git/HEAD if it's deployed alongside the app.
        $headFile = base_path('.git/HEAD');
        if (is_readable($headFile)) {
            $head = trim((string) file_get_contents($headFile));
            if (str_starts_with($head, 'ref: ')) {
                $refFile = base_path('.git/'.substr($head, 5));
                if (is_readable($refFile)) {
                    return substr(trim((string) file_get_contents($refFile)), 0, 7);
                }
            }

            return substr($head, 0, 7);
        }

        return 'unknown';
    }
}
