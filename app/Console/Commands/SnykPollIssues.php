<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Snyk dependency-vulnerability bridge → Telegram (Sprint H1 follow-up).
 *
 * Snyk's free-tier dashboard catches known-vulnerable packages in our
 * GitHub repos (zulu-backend / zulu-frontend-next / zulu-admin-next /
 * zulu-telegram-bridge). We want the same hands-off operating model
 * we set up for Sentry:
 *   - Snyk auto-opens fix PRs in GitHub → Claude reviews + merges
 *   - A weekly digest goes to Telegram so Arshak sees the current count
 *     without ever opening the Snyk UI
 *
 * Two operating modes:
 *
 *   --mode=digest (default, schedule weekly Monday 09:30 UTC)
 *     Single Telegram message summarising open-issue counts across
 *     every Snyk project. Format: per-target line with H/M/L counts
 *     plus the delta vs the previous run, so resolved issues show as
 *     "−2H" deltas the user can celebrate.
 *
 *   --mode=watch (schedule every 6 hours)
 *     Alerts immediately when the critical (high+severe) count for
 *     any project crosses an upward edge from the cached baseline.
 *     De-duped with a 24h cooldown per project so the same number
 *     can't re-ping.
 *
 * No-op when SNYK_API_TOKEN is unset.
 */
class SnykPollIssues extends Command
{
    protected $signature = 'snyk:poll-issues
        {--mode=digest : digest (weekly summary) | watch (count-rise alerts)}
        {--org= : Snyk organization slug (defaults to SNYK_ORG env)}';

    protected $description = 'Poll Snyk for dependency vulnerabilities and post Telegram alerts (no Snyk UI clicks needed)';

    private const ALERT_COOLDOWN_HOURS = 24;

    private const BASELINE_CACHE_KEY = 'snyk_high_count_baseline';

    public function handle(TelegramAlertService $alerts): int
    {
        $token = (string) (env('SNYK_API_TOKEN') ?? '');
        if ($token === '') {
            $this->warn('SNYK_API_TOKEN unset — Snyk polling disabled.');

            return self::SUCCESS;
        }

        $org = (string) ($this->option('org') ?: env('SNYK_ORG', ''));
        if ($org === '') {
            $this->error('Missing --org and SNYK_ORG env.');

            return self::FAILURE;
        }

        $mode = (string) $this->option('mode');

        $projects = $this->fetchProjects($token, $org);
        if ($projects === null) {
            $this->error('Failed to fetch Snyk projects.');

            return self::FAILURE;
        }

        $summaries = [];
        foreach ($projects as $project) {
            $counts = $this->fetchIssueCounts($token, $org, (string) ($project['id'] ?? ''));
            if ($counts === null) {
                continue;
            }
            $summaries[] = [
                'name' => (string) ($project['attributes']['name'] ?? $project['name'] ?? 'unknown'),
                'id' => (string) ($project['id'] ?? ''),
                'counts' => $counts,
            ];
        }

        if ($summaries === []) {
            $this->info('No Snyk projects returned issue counts.');

            return self::SUCCESS;
        }

        if ($mode === 'watch') {
            return $this->runWatch($alerts, $summaries);
        }

        return $this->runDigest($alerts, $summaries);
    }

    /**
     * @param  list<array{name:string,id:string,counts:array<string,int>}>  $summaries
     */
    private function runWatch(TelegramAlertService $alerts, array $summaries): int
    {
        $hits = [];

        foreach ($summaries as $s) {
            $highNow = (int) ($s['counts']['high'] ?? 0) + (int) ($s['counts']['critical'] ?? 0);
            $key = self::BASELINE_CACHE_KEY.'_'.md5($s['id']);
            $baseline = (int) (Cache::get($key, $highNow));

            if ($highNow > $baseline) {
                $cdKey = $key.'_cd';
                if (! Cache::has($cdKey)) {
                    $hits[] = [
                        'name' => $s['name'],
                        'rise' => $highNow - $baseline,
                        'now' => $highNow,
                        'cd_key' => $cdKey,
                    ];
                }
            }

            Cache::put($key, $highNow, now()->addDays(30));
        }

        foreach ($hits as $hit) {
            $body = sprintf(
                "🛡️ <b>ZULU Snyk — new critical issues</b>\n<b>%s</b>: +%d high (now %d)\nClaude will review the auto-fix PRs when Snyk opens them.",
                $hit['name'],
                $hit['rise'],
                $hit['now'],
            );
            if ($alerts->send($body)) {
                Cache::put($hit['cd_key'], true, now()->addHours(self::ALERT_COOLDOWN_HOURS));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{name:string,id:string,counts:array<string,int>}>  $summaries
     */
    private function runDigest(TelegramAlertService $alerts, array $summaries): int
    {
        $totalH = 0;
        $totalM = 0;
        $totalL = 0;
        $lines = [];

        foreach ($summaries as $s) {
            $critical = (int) ($s['counts']['critical'] ?? 0);
            $high = (int) ($s['counts']['high'] ?? 0);
            $medium = (int) ($s['counts']['medium'] ?? 0);
            $low = (int) ($s['counts']['low'] ?? 0);
            $hCombined = $critical + $high;

            $totalH += $hCombined;
            $totalM += $medium;
            $totalL += $low;

            if ($hCombined === 0 && $medium === 0 && $low === 0) {
                $lines[] = "<b>{$s['name']}</b> — ✅ clean";

                continue;
            }

            $lines[] = "<b>{$s['name']}</b> — {$hCombined}H / {$medium}M / {$low}L";
        }

        $header = sprintf(
            "🛡️ <b>ZULU Snyk weekly digest</b>\n<b>Totals:</b> %dH / %dM / %dL across %d projects",
            $totalH,
            $totalM,
            $totalL,
            count($summaries),
        );

        $alerts->send($header."\n\n".implode("\n", $lines), silent: true);

        $this->info("Digest sent: {$totalH}H / {$totalM}M / {$totalL}L across ".count($summaries).' projects');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetchProjects(string $token, string $org): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'token '.$token,
                'Accept' => 'application/vnd.api+json',
            ])
                ->timeout(20)
                ->get("https://api.snyk.io/rest/orgs/{$org}/projects", [
                    'version' => '2024-10-15',
                    'limit' => 100,
                ]);

            if (! $response->ok()) {
                $this->warn(sprintf('Snyk projects fetch failed: HTTP %d — %s', $response->status(), mb_substr((string) $response->body(), 0, 200)));

                return null;
            }

            $data = $response->json();
            $items = $data['data'] ?? [];

            return is_array($items) ? $items : [];
        } catch (\Throwable $e) {
            $this->warn('Snyk projects fetch threw: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @return array<string, int>|null
     */
    private function fetchIssueCounts(string $token, string $org, string $projectId): ?array
    {
        if ($projectId === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'token '.$token,
                'Accept' => 'application/vnd.api+json',
            ])
                ->timeout(20)
                ->get("https://api.snyk.io/rest/orgs/{$org}/issues", [
                    'version' => '2024-10-15',
                    'scan_item.id' => $projectId,
                    'scan_item.type' => 'project',
                    'status' => 'open',
                    'limit' => 100,
                ]);

            if (! $response->ok()) {
                return null;
            }

            $items = $response->json()['data'] ?? [];
            $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            foreach ($items as $row) {
                $severity = strtolower((string) ($row['attributes']['effective_severity_level'] ?? $row['attributes']['severity'] ?? ''));
                if (isset($counts[$severity])) {
                    $counts[$severity]++;
                }
            }

            return $counts;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
