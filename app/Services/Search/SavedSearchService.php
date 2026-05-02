<?php

namespace App\Services\Search;

use App\Models\SavedSearch;
use App\Models\SearchQueryLog;
use App\Models\User;
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
}
