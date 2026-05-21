<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Snyk + Dependabot bridge → Telegram via GitHub PRs.
 *
 * Snyk's free plan blocks programmatic vulnerability counts via its REST
 * API ("not entitled for api access"), but its GitHub integration still
 * works: when Snyk finds a fixable vuln in one of our repos it opens a
 * PR under the snyk-bot user. Same applies to dependabot[bot] (GitHub's
 * built-in scanner). Both are far more useful than raw issue counts —
 * they're actionable.
 *
 * This command queries GitHub for open PRs by those bot accounts across
 * the four ZULU repos and posts a Telegram digest. The point is so that
 * Արշակ never opens the Snyk dashboard; he sees PRs in Telegram and
 * Claude reviews + merges them.
 *
 * Two operating modes:
 *
 *   --mode=digest (default, schedule weekly Monday 09:30 UTC)
 *     One Telegram message listing every open Snyk/Dependabot PR
 *     across all four repos with titles + URLs. Useful as the
 *     "what's piled up" weekly glance.
 *
 *   --mode=watch (schedule every 6 hours)
 *     Alerts immediately when a new PR appears since the last cached
 *     baseline. Per-PR cooldown so the same PR can't re-ping.
 *
 * No-op when GITHUB_TOKEN (or GH_TOKEN) is unset.
 */
class SnykPollIssues extends Command
{
    protected $signature = 'snyk:poll-issues
        {--mode=digest : digest (weekly summary) | watch (new-PR alerts)}
        {--repos= : Comma-separated owner/name list (default: the four ZULU repos)}';

    protected $description = 'Watch GitHub for Snyk/Dependabot PRs across ZULU repos and post Telegram digests (no Snyk UI clicks needed)';

    private const DEFAULT_REPOS = [
        'arshakbabayan-sudo/zulu-backend',
        'arshakbabayan-sudo/zulu-frontend-next',
        'arshakbabayan-sudo/zulu-admin-next',
        'arshakbabayan-sudo/zulu-telegram-bridge',
    ];

    private const BOT_LOGINS = ['snyk-bot', 'dependabot[bot]'];

    private const ALERT_COOLDOWN_HOURS = 24;

    public function handle(TelegramAlertService $alerts): int
    {
        $token = (string) (env('GITHUB_TOKEN') ?? env('GH_TOKEN') ?? '');
        if ($token === '') {
            $this->warn('GITHUB_TOKEN / GH_TOKEN unset — Snyk-via-GitHub polling disabled.');

            return self::SUCCESS;
        }

        $reposRaw = (string) ($this->option('repos') ?? '');
        $repos = $reposRaw === ''
            ? self::DEFAULT_REPOS
            : array_map('trim', explode(',', $reposRaw));

        $mode = (string) $this->option('mode');

        $allPrs = [];
        foreach ($repos as $repo) {
            $prs = $this->fetchBotPrs($token, $repo);
            if ($prs === null) {
                $this->warn("Failed to fetch PRs for {$repo}");

                continue;
            }
            foreach ($prs as $pr) {
                $pr['_repo'] = $repo;
                $allPrs[] = $pr;
            }
        }

        if ($mode === 'watch') {
            return $this->runWatch($alerts, $allPrs);
        }

        return $this->runDigest($alerts, $allPrs, $repos);
    }

    /**
     * @param  list<array<string, mixed>>  $prs
     */
    private function runWatch(TelegramAlertService $alerts, array $prs): int
    {
        $newHits = [];

        foreach ($prs as $pr) {
            $key = 'snyk_pr_'.md5(($pr['html_url'] ?? '').$pr['number']);
            if (Cache::has($key)) {
                continue;
            }
            $newHits[] = $pr;
        }

        if ($newHits === []) {
            $this->info('No new bot PRs across '.count($prs).' existing.');

            return self::SUCCESS;
        }

        foreach ($newHits as $pr) {
            $body = sprintf(
                "🛡️ <b>New dependency-fix PR</b>\n<b>%s</b>\n<code>%s</code>\n<a href=\"%s\">Open in GitHub</a>",
                $pr['_repo'],
                $this->trim((string) ($pr['title'] ?? '?'), 100),
                (string) ($pr['html_url'] ?? ''),
            );
            if ($alerts->send($body)) {
                $key = 'snyk_pr_'.md5(($pr['html_url'] ?? '').$pr['number']);
                Cache::put($key, true, now()->addHours(self::ALERT_COOLDOWN_HOURS));
                $this->info("Alerted: {$pr['_repo']} #{$pr['number']}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $prs
     * @param  list<string>  $repos
     */
    private function runDigest(TelegramAlertService $alerts, array $prs, array $repos): int
    {
        if ($prs === []) {
            $body = sprintf(
                "🛡️ <b>ZULU dependency-fix digest</b>\nNo open Snyk / Dependabot PRs across %d repos. ✅",
                count($repos),
            );
            $alerts->send($body, silent: true);
            $this->info('Digest sent: 0 open PRs');

            return self::SUCCESS;
        }

        // Group by repo
        $byRepo = [];
        foreach ($prs as $pr) {
            $byRepo[$pr['_repo']][] = $pr;
        }

        $sections = [];
        foreach ($byRepo as $repo => $list) {
            $shortRepo = $this->trim(explode('/', $repo)[1] ?? $repo, 40);
            $lines = ['<b>'.$shortRepo.'</b> ('.count($list).' PRs)'];
            foreach (array_slice($list, 0, 5) as $pr) {
                $title = $this->trim((string) ($pr['title'] ?? '?'), 70);
                $url = (string) ($pr['html_url'] ?? '');
                $lines[] = "  • <a href=\"{$url}\">#{$pr['number']}</a> {$title}";
            }
            if (count($list) > 5) {
                $extra = count($list) - 5;
                $lines[] = "  • ... and {$extra} more";
            }
            $sections[] = implode("\n", $lines);
        }

        $header = sprintf(
            "🛡️ <b>ZULU dependency-fix digest</b>\n<b>Total:</b> %d open PRs across %d repos",
            count($prs),
            count($byRepo),
        );

        $alerts->send($header."\n\n".implode("\n\n", $sections), silent: true);

        $this->info('Digest sent: '.count($prs).' open PRs across '.count($byRepo).' repos');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetchBotPrs(string $token, string $repo): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'zulu-monitoring',
            ])
                ->timeout(15)
                ->get("https://api.github.com/repos/{$repo}/pulls", [
                    'state' => 'open',
                    'per_page' => 100,
                ]);

            if (! $response->ok()) {
                $this->warn(sprintf('GitHub fetch %s failed: HTTP %d', $repo, $response->status()));

                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            return array_values(array_filter(
                $data,
                fn ($pr) => in_array(strtolower((string) ($pr['user']['login'] ?? '')), self::BOT_LOGINS, true),
            ));
        } catch (\Throwable $e) {
            $this->warn('GitHub fetch '.$repo.' threw: '.$e->getMessage());

            return null;
        }
    }

    private function trim(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1).'…';
    }
}
