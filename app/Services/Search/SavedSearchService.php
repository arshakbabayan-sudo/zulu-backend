<?php

namespace App\Services\Search;

use App\Models\SavedSearch;
use App\Models\SearchQueryLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PART 20 — Saved searches + query log + popular-queries surfacing.
 */
class SavedSearchService
{
    /**
     * @param  array<string, mixed>  $payload  name, module_type, query_string, filters, alerts_enabled, result_count_at_save
     */
    public function save(User $user, array $payload): SavedSearch
    {
        return SavedSearch::query()->create([
            'user_id' => $user->id,
            'name' => $payload['name'] ?? null,
            'module_type' => $payload['module_type'] ?? null,
            'query_string' => $payload['query_string'] ?? null,
            'filters' => is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
            'alerts_enabled' => (bool) ($payload['alerts_enabled'] ?? false),
            'last_run_at' => null,
            'result_count_at_save' => isset($payload['result_count_at_save']) ? (int) $payload['result_count_at_save'] : null,
        ]);
    }

    /**
     * @return Collection<int, SavedSearch>
     */
    public function listForUser(User $user): Collection
    {
        return SavedSearch::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function delete(User $user, int $id): bool
    {
        $search = SavedSearch::query()->where('user_id', $user->id)->find($id);
        if ($search === null) {
            return false;
        }

        return (bool) $search->delete();
    }

    public function toggleAlerts(User $user, int $id, bool $enabled): ?SavedSearch
    {
        $search = SavedSearch::query()->where('user_id', $user->id)->find($id);
        if ($search === null) {
            return null;
        }

        $search->alerts_enabled = $enabled;
        $search->save();

        return $search->fresh();
    }

    /**
     * Log a search query (anonymous if user is null).
     *
     * @param  array<string, mixed>  $filters
     */
    public function logQuery(?User $user, ?string $queryString, ?string $moduleType, array $filters, int $resultCount): SearchQueryLog
    {
        return SearchQueryLog::query()->create([
            'user_id' => $user?->id,
            'query_string' => $queryString,
            'module_type' => $moduleType,
            'filters' => $filters,
            'result_count' => $resultCount,
            'happened_at' => now(),
        ]);
    }

    /**
     * Most-frequent recent queries (last 30 days).
     *
     * @return array<int, array{query_string: string, module_type: ?string, count: int}>
     */
    public function popularQueries(int $limit = 20, int $days = 30): array
    {
        $rows = SearchQueryLog::query()
            ->select(['query_string', 'module_type', DB::raw('count(*) as count')])
            ->where('happened_at', '>=', now()->subDays($days))
            ->whereNotNull('query_string')
            ->where('query_string', '!=', '')
            ->groupBy('query_string', 'module_type')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'query_string' => $r->query_string,
            'module_type' => $r->module_type,
            'count' => (int) $r->count,
        ])->all();
    }

    /**
     * Autocomplete suggestions from logged queries (prefix match, distinct).
     *
     * @return array<int, string>
     */
    public function autocomplete(string $prefix, int $limit = 10): array
    {
        if (strlen(trim($prefix)) < 2) {
            return [];
        }

        return SearchQueryLog::query()
            ->where('query_string', 'like', $prefix.'%')
            ->whereNotNull('query_string')
            ->groupBy('query_string')
            ->orderByRaw('count(*) desc')
            ->limit($limit)
            ->pluck('query_string')
            ->all();
    }

    /**
     * Search trends over time (Sprint 68, PART 20 deferred parts).
     *
     * Returns a daily time series of search volume per module_type so admin
     * dashboards can chart "flight searches per day", etc.
     *
     * @return array<int, array{date: string, total: int, by_module: array<string,int>}>
     */
    public function searchTrends(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $cutoff = now()->startOfDay()->subDays($days - 1);

        $rows = SearchQueryLog::query()
            ->selectRaw("date_trunc('day', happened_at) as bucket, module_type, count(*) as count")
            ->where('happened_at', '>=', $cutoff)
            ->groupBy('bucket', 'module_type')
            ->orderBy('bucket')
            ->get();

        $byDate = [];
        foreach ($rows as $r) {
            $date = CarbonImmutable::parse($r->bucket)->toDateString();
            $module = (string) ($r->module_type ?? 'unknown');
            $byDate[$date][$module] = (int) $r->count;
        }

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $cutoff->copy()->addDays($i)->toDateString();
            $byModule = $byDate[$date] ?? [];
            $series[] = [
                'date' => $date,
                'total' => array_sum($byModule),
                'by_module' => $byModule,
            ];
        }

        return $series;
    }

    /**
     * Cross-vertical match suggestions (Sprint 68).
     *
     * Given a query, suggests sibling verticals that frequently co-occur
     * (e.g. people searching "Yerevan flight" also search "Yerevan hotel").
     * Uses query_string overlap on the same calendar day as the heuristic —
     * simple but explainable, no ML required.
     *
     * @return array<int, array{module_type: string, score: float, sample_query: string}>
     */
    public function crossVerticalMatches(string $queryString, int $days = 30, int $limit = 5): array
    {
        $queryString = trim($queryString);
        if ($queryString === '') {
            return [];
        }

        $cutoff = now()->subDays(max(1, min(365, $days)));

        // Find sessions / users who searched the given query, then find their other module_types.
        $userIds = SearchQueryLog::query()
            ->where('query_string', 'like', "%{$queryString}%")
            ->where('happened_at', '>=', $cutoff)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->take(500)
            ->all();

        if ($userIds === []) {
            return [];
        }

        $rows = SearchQueryLog::query()
            ->select(['module_type', DB::raw('count(*) as count'), DB::raw('min(query_string) as sample_query')])
            ->whereIn('user_id', $userIds)
            ->where('happened_at', '>=', $cutoff)
            ->where('query_string', 'not like', "%{$queryString}%")
            ->whereNotNull('module_type')
            ->groupBy('module_type')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();

        $maxCount = (int) ($rows->max('count') ?: 1);

        return $rows->map(fn ($r) => [
            'module_type' => (string) $r->module_type,
            'score' => round(((int) $r->count) / $maxCount, 3),
            'sample_query' => (string) ($r->sample_query ?? ''),
        ])->all();
    }
}
