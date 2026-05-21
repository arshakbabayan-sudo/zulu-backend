<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sentry issue → Telegram bridge (Sprint H1 follow-up).
 *
 * Polls the Sentry REST API for new + recent issues across all three
 * ZULU projects (zulu-backend / zulu-frontend / zulu-admin) and posts
 * Telegram alerts via the same bot used for deploy notifications. The
 * idea is that Արշակ never has to open the Sentry UI to find out
 * something broke.
 *
 * Two operating modes:
 *
 *   --mode=watch  (default, schedule every 10 minutes)
 *     Sends an alert immediately when a critical issue surfaces:
 *       - level = error/fatal AND issue is first-seen in the last hour
 *       - OR event count in the last 10 minutes >= 5
 *     De-duped via cache (per-issue key, 24h TTL) so the same issue
 *     never re-pings within a day.
 *
 *   --mode=digest (schedule once a day at 09:00 UTC)
 *     Sends a roll-up of every unresolved issue across all three
 *     projects with a 24h event count > 0. Single Telegram message
 *     formatted as an HTML table. Useful as the "morning glance".
 *
 * No-op when SENTRY_API_TOKEN is unset — keeps dev / CI quiet without
 * needing a fake token.
 */
class SentryPollIssues extends Command
{
    protected $signature = 'sentry:poll-issues
        {--mode=watch : watch (immediate alerts) | digest (daily summary)}
        {--projects= : Comma-separated project slugs to scan (default: all three)}
        {--org=zulu-platform : Sentry organization slug}
        {--region= : Sentry region host override (defaults to SENTRY_API_REGION env, falls back to sentry.io)}';

    protected $description = 'Poll Sentry for new/critical issues and post Telegram alerts (no Sentry UI clicks needed)';

    private const DEFAULT_PROJECTS = ['zulu-backend', 'zulu-frontend', 'zulu-admin'];

    private const ALERT_COOLDOWN_HOURS = 24;

    private const WATCH_FIRST_SEEN_WINDOW_MIN = 60;

    private const WATCH_BURST_WINDOW_MIN = 10;

    private const WATCH_BURST_EVENT_THRESHOLD = 5;

    public function handle(TelegramAlertService $alerts): int
    {
        $token = (string) (env('SENTRY_API_TOKEN') ?? '');
        if ($token === '') {
            $this->warn('SENTRY_API_TOKEN unset — Sentry polling disabled.');

            return self::SUCCESS;
        }

        $mode = (string) $this->option('mode');
        $org = (string) $this->option('org');
        $projectsRaw = (string) ($this->option('projects') ?? '');
        $projects = $projectsRaw === ''
            ? self::DEFAULT_PROJECTS
            : array_map('trim', explode(',', $projectsRaw));

        // ZULU's Sentry org is in the EU region (de.sentry.io). Org tokens
        // generated under that org only authenticate against the matching
        // region host — calls to sentry.io would 401 silently.
        $region = (string) ($this->option('region') ?: env('SENTRY_API_REGION', 'de.sentry.io'));
        $apiBase = 'https://'.$region.'/api/0';

        if ($mode === 'digest') {
            return $this->runDigest($alerts, $token, $apiBase, $org, $projects);
        }

        return $this->runWatch($alerts, $token, $apiBase, $org, $projects);
    }

    /**
     * @param  list<string>  $projects
     */
    private function runWatch(TelegramAlertService $alerts, string $token, string $apiBase, string $org, array $projects): int
    {
        $hits = [];

        foreach ($projects as $project) {
            $issues = $this->fetchIssues($token, $apiBase, $org, $project, 'is:unresolved is:unassigned', 25);
            if ($issues === null) {
                $this->warn("Failed to fetch issues for {$project}");

                continue;
            }

            foreach ($issues as $issue) {
                if (! $this->shouldAlertOnIssue($issue)) {
                    continue;
                }

                $key = 'sentry_alert_'.($issue['id'] ?? md5(json_encode($issue)));
                if (Cache::has($key)) {
                    continue; // cooldown
                }

                $hits[] = [
                    'project' => $project,
                    'issue' => $issue,
                    'cache_key' => $key,
                ];
            }
        }

        if ($hits === []) {
            if (! $this->option('quiet')) {
                $this->info('No new critical issues across '.implode(', ', $projects));
            }

            return self::SUCCESS;
        }

        foreach ($hits as $hit) {
            $body = $this->formatWatchAlert($hit['project'], $hit['issue']);
            $sent = $alerts->send($body);
            if ($sent) {
                Cache::put($hit['cache_key'], true, now()->addHours(self::ALERT_COOLDOWN_HOURS));
                $this->info('Alerted: '.($hit['issue']['shortId'] ?? '?'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $projects
     */
    private function runDigest(TelegramAlertService $alerts, string $token, string $apiBase, string $org, array $projects): int
    {
        $totalEvents = 0;
        $totalUsers = 0;
        $lines = [];

        foreach ($projects as $project) {
            $issues = $this->fetchIssues($token, $apiBase, $org, $project, 'is:unresolved', 10);
            if ($issues === null) {
                $lines[] = "<b>{$project}</b> — fetch failed";

                continue;
            }

            if ($issues === []) {
                $lines[] = "<b>{$project}</b> — ✅ clean";

                continue;
            }

            $projEvents = 0;
            $projUsers = 0;
            $topIssues = [];

            foreach ($issues as $issue) {
                $count = (int) ($issue['count'] ?? 0);
                $userCount = (int) ($issue['userCount'] ?? 0);
                $projEvents += $count;
                $projUsers += $userCount;

                if (count($topIssues) < 3) {
                    $title = $this->trim((string) ($issue['title'] ?? '?'), 60);
                    $shortId = (string) ($issue['shortId'] ?? '?');
                    $topIssues[] = "  • <code>{$shortId}</code> {$title} ({$count} events, {$userCount} users)";
                }
            }

            $totalEvents += $projEvents;
            $totalUsers += $projUsers;

            $section = "<b>{$project}</b> — ".count($issues)." issues, {$projEvents} events, {$projUsers} users\n".implode("\n", $topIssues);
            $lines[] = $section;
        }

        $today = Carbon::now('UTC')->format('Y-m-d H:i');
        $header = "📊 <b>ZULU Sentry digest</b> ({$today} UTC)\n<b>Total:</b> {$totalEvents} events, {$totalUsers} users affected (last 24h)\n";

        $alerts->send($header."\n".implode("\n\n", $lines), silent: true);

        $this->info("Digest sent: {$totalEvents} events, {$totalUsers} users across ".count($projects).' projects');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetchIssues(string $token, string $apiBase, string $org, string $project, string $query, int $limit): ?array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$apiBase}/projects/{$org}/{$project}/issues/", [
                    'query' => $query,
                    'statsPeriod' => '24h',
                    'limit' => $limit,
                ]);

            if (! $response->ok()) {
                $this->warn(sprintf(
                    'Sentry %s/%s fetch failed: HTTP %d — %s',
                    $org,
                    $project,
                    $response->status(),
                    mb_substr((string) $response->body(), 0, 200),
                ));

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->warn(sprintf('Sentry %s/%s fetch threw: %s', $org, $project, $e->getMessage()));

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function shouldAlertOnIssue(array $issue): bool
    {
        $level = strtolower((string) ($issue['level'] ?? 'info'));
        if (! in_array($level, ['error', 'fatal'], true)) {
            return false;
        }

        // Brand-new issue surfaced in the last hour: always alert.
        $firstSeen = isset($issue['firstSeen']) ? Carbon::parse((string) $issue['firstSeen']) : null;
        if ($firstSeen !== null && $firstSeen->isAfter(Carbon::now('UTC')->subMinutes(self::WATCH_FIRST_SEEN_WINDOW_MIN))) {
            return true;
        }

        // Burst on an existing issue: count >= threshold in the last 10 min.
        // The Sentry list endpoint returns a coarse 'count' over the
        // statsPeriod window; we treat the 24h total as a soft signal and
        // alert when it crossed the burst threshold AND lastSeen is recent.
        $lastSeen = isset($issue['lastSeen']) ? Carbon::parse((string) $issue['lastSeen']) : null;
        $count = (int) ($issue['count'] ?? 0);
        if (
            $lastSeen !== null
            && $lastSeen->isAfter(Carbon::now('UTC')->subMinutes(self::WATCH_BURST_WINDOW_MIN))
            && $count >= self::WATCH_BURST_EVENT_THRESHOLD
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function formatWatchAlert(string $project, array $issue): string
    {
        $title = $this->trim((string) ($issue['title'] ?? 'Unknown issue'), 120);
        $shortId = (string) ($issue['shortId'] ?? '?');
        $count = (int) ($issue['count'] ?? 0);
        $userCount = (int) ($issue['userCount'] ?? 0);
        $level = strtoupper((string) ($issue['level'] ?? 'error'));
        $permalink = (string) ($issue['permalink'] ?? '');

        return implode("\n", [
            "🚨 <b>ZULU [{$project}] {$level}</b>",
            "<code>{$shortId}</code> {$title}",
            "Events: {$count} | Users: {$userCount}",
            $permalink !== '' ? "<a href=\"{$permalink}\">Open in Sentry</a>" : '',
        ]);
    }

    private function trim(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1).'…';
    }
}
