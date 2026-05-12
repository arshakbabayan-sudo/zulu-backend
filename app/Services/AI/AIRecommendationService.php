<?php

namespace App\Services\AI;

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Package recommendations for a customer.
 *
 * Two tiers:
 *   Tier 1 (always available): heuristic recommender. Looks at the
 *     user's past orders, extracts (destination_country, price_band,
 *     traveler_count) signals, returns up to N currently-published
 *     packages that share at least one signal. No external API.
 *
 *   Tier 2 (requires ANTHROPIC_API_KEY): semantic re-ranker. Feeds the
 *     heuristic candidates plus user metadata to Claude, asks it to
 *     re-rank with a one-line explanation per pick. The explanation
 *     surfaces on the recommendation card.
 *
 * The service degrades cleanly: if Anthropic is unconfigured, returns
 * the heuristic list as-is. The frontend renders the same card layout
 * either way — only the explanation field stays empty.
 */
class AIRecommendationService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key', env('ANTHROPIC_API_KEY', ''));
    }

    public function isAiAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @return array<int, array{package: Package, score: float, reason: ?string}>
     */
    public function recommendForUser(User $user, int $limit = 6): array
    {
        $signals = $this->extractUserSignals($user);
        $candidates = $this->fetchHeuristicCandidates($signals, $limit * 2);

        if ($candidates === []) {
            // Cold-start: fall back to most-popular published packages.
            $candidates = $this->fetchPopularCandidates($limit * 2);
        }

        $scored = $this->scoreHeuristically($candidates, $signals);

        // Trim to the most relevant before paying the AI tokens for
        // re-ranking; saves cost and latency without losing quality
        // (Claude rarely picks past the top-K of a sensible heuristic).
        $top = array_slice($scored, 0, $limit);

        if (! $this->isAiAvailable() || count($top) < 2) {
            return array_map(fn ($row) => [
                'package' => $row['package'],
                'score' => $row['score'],
                'reason' => null,
            ], $top);
        }

        return $this->semanticRerank($top, $user, $signals);
    }

    /**
     * @return array{countries: array<string>, price_band: ?string, traveler_count_avg: ?int, past_package_ids: array<int>}
     */
    private function extractUserSignals(User $user): array
    {
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->with('items')
            ->latest()
            ->limit(20)
            ->get();

        $countries = [];
        $prices = [];
        $travelerCounts = [];
        $packageIds = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (! empty($item->metadata['destination_country'])) {
                    $countries[] = $item->metadata['destination_country'];
                }
                if (! empty($item->metadata['travelers'])) {
                    $travelerCounts[] = (int) $item->metadata['travelers'];
                }
                if (! empty($item->package_id)) {
                    $packageIds[] = (int) $item->package_id;
                }
            }
            if ($order->total_amount > 0) {
                $prices[] = (float) $order->total_amount;
            }
        }

        $avgPrice = $prices === [] ? null : array_sum($prices) / count($prices);

        return [
            'countries' => array_values(array_unique($countries)),
            'price_band' => $this->priceBand($avgPrice),
            'traveler_count_avg' => $travelerCounts === [] ? null : (int) round(array_sum($travelerCounts) / count($travelerCounts)),
            'past_package_ids' => array_values(array_unique($packageIds)),
        ];
    }

    /**
     * @param  array{countries: array<string>, price_band: ?string, traveler_count_avg: ?int, past_package_ids: array<int>}  $signals
     * @return array<int, Package>
     */
    private function fetchHeuristicCandidates(array $signals, int $limit): array
    {
        $query = Package::query()
            ->where('status', 'published')
            ->whereNotIn('id', $signals['past_package_ids']);

        if ($signals['countries'] !== []) {
            $query->whereIn('destination_country', $signals['countries']);
        }

        return $query
            ->orderByDesc('featured')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return array<int, Package>
     */
    private function fetchPopularCandidates(int $limit): array
    {
        // Most-booked published packages over the last 90 days.
        return Package::query()
            ->where('status', 'published')
            ->leftJoin('order_items', 'order_items.package_id', '=', 'packages.id')
            ->select('packages.*', DB::raw('COUNT(order_items.id) as bookings_90d'))
            ->where(function ($q) {
                $q->whereNull('order_items.created_at')
                    ->orWhere('order_items.created_at', '>=', now()->subDays(90));
            })
            ->groupBy('packages.id')
            ->orderByDesc('bookings_90d')
            ->orderByDesc('packages.created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<int, Package>  $candidates
     * @param  array{countries: array<string>, price_band: ?string, traveler_count_avg: ?int, past_package_ids: array<int>}  $signals
     * @return array<int, array{package: Package, score: float}>
     */
    private function scoreHeuristically(array $candidates, array $signals): array
    {
        $scored = [];
        foreach ($candidates as $pkg) {
            $score = 0.0;
            if (in_array($pkg->destination_country ?? '', $signals['countries'], true)) {
                $score += 1.0;
            }
            $band = $this->priceBand((float) ($pkg->base_price ?? 0));
            if ($band !== null && $band === $signals['price_band']) {
                $score += 0.5;
            }
            if (! empty($pkg->featured)) {
                $score += 0.2;
            }
            $scored[] = ['package' => $pkg, 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * @param  array<int, array{package: Package, score: float}>  $candidates
     * @param  array{countries: array<string>, price_band: ?string, traveler_count_avg: ?int, past_package_ids: array<int>}  $signals
     * @return array<int, array{package: Package, score: float, reason: ?string}>
     */
    private function semanticRerank(array $candidates, User $user, array $signals): array
    {
        try {
            $candidatesPayload = [];
            foreach ($candidates as $row) {
                $pkg = $row['package'];
                $candidatesPayload[] = [
                    'id' => $pkg->id,
                    'title' => $pkg->title ?? '',
                    'destination' => $pkg->destination_country ?? '',
                    'price' => $pkg->base_price ?? null,
                    'duration_nights' => $pkg->min_nights ?? null,
                ];
            }

            $system = <<<'TXT'
You re-rank ZULU package recommendations for a returning customer.
Input: a JSON object with `user_signals` (countries, price_band,
traveler_count) and `candidates` (id, title, destination, price,
duration_nights).

Output: ONLY a JSON array of {id, reason} where reason is a short
sentence (≤ 12 words) in English explaining why this package fits.
Keep the same id values; reorder so the most relevant is first.
Never invent fields beyond id + reason. No prose outside the JSON.
TXT;

            $response = Http::timeout(20)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1024,
                    'system' => $system,
                    'messages' => [[
                        'role' => 'user',
                        'content' => json_encode([
                            'user_signals' => $signals,
                            'candidates' => $candidatesPayload,
                        ]),
                    ]],
                ]);

            if (! $response->successful()) {
                return $this->withoutReasons($candidates);
            }

            $text = (string) ($response->json()['content'][0]['text'] ?? '');
            $parsed = json_decode($text, true);
            if (! is_array($parsed)) {
                return $this->withoutReasons($candidates);
            }

            $reasonsById = [];
            foreach ($parsed as $row) {
                if (isset($row['id'])) {
                    $reasonsById[(int) $row['id']] = (string) ($row['reason'] ?? '');
                }
            }

            $byId = [];
            foreach ($candidates as $row) {
                $byId[(int) $row['package']->id] = $row;
            }

            $reranked = [];
            foreach ($parsed as $row) {
                $id = (int) ($row['id'] ?? 0);
                if (isset($byId[$id])) {
                    $reranked[] = [
                        'package' => $byId[$id]['package'],
                        'score' => $byId[$id]['score'],
                        'reason' => $reasonsById[$id] ?? null,
                    ];
                    unset($byId[$id]);
                }
            }

            // Append any candidates Claude didn't mention to keep limit stable.
            foreach ($byId as $row) {
                $reranked[] = [
                    'package' => $row['package'],
                    'score' => $row['score'],
                    'reason' => null,
                ];
            }

            return $reranked;
        } catch (\Throwable $e) {
            Log::warning('AIRecommendationService::semanticRerank fallthrough', ['error' => $e->getMessage()]);

            return $this->withoutReasons($candidates);
        }
    }

    /**
     * @param  array<int, array{package: Package, score: float}>  $candidates
     * @return array<int, array{package: Package, score: float, reason: ?string}>
     */
    private function withoutReasons(array $candidates): array
    {
        return array_map(fn ($row) => [
            'package' => $row['package'],
            'score' => $row['score'],
            'reason' => null,
        ], $candidates);
    }

    private function priceBand(?float $amount): ?string
    {
        if ($amount === null) {
            return null;
        }
        if ($amount < 200) {
            return 'budget';
        }
        if ($amount < 800) {
            return 'mid';
        }

        return 'premium';
    }
}
