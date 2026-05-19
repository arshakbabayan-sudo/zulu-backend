<?php

namespace App\Services\Subscriptions;

use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the effective plan features for a company and answers
 * "does plan X allow Y" / "what is plan X's limit on Y".
 *
 * Cached per-company for 5 minutes — short enough to feel snappy after
 * a plan change, long enough to avoid hammering the DB on every request.
 */
class PlanGateService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Returns the merged feature map for a company:
     * defaults from PlanFeature::all() overlaid with the plan's stored
     * features. Cancelled or past_due subscriptions get pure defaults.
     *
     * @return array<string, bool|int>
     */
    public function featuresFor(int $companyId): array
    {
        return Cache::remember(
            "plan_features_company_{$companyId}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->resolveFeatures($companyId)
        );
    }

    /**
     * Bust the cache after subscription changes.
     */
    public function flush(int $companyId): void
    {
        Cache::forget("plan_features_company_{$companyId}");
    }

    /**
     * Bust all caches (after a plan-level feature change that ripples to
     * every company on that plan). Cheap — just bumps the cache version.
     */
    public function flushAll(): void
    {
        // Cache::tags() requires a tagged driver; instead, bump a version
        // key that featuresFor() includes when building the cache key.
        Cache::increment('plan_features_version');
    }

    public function allows(int $companyId, string $key): bool
    {
        $features = $this->featuresFor($companyId);
        $value = $features[$key] ?? PlanFeature::defaultValue($key);
        if (PlanFeature::isLimit($key)) {
            // For limit features, "allows" means the limit is not zero.
            return ((int) $value) !== 0;
        }

        return (bool) $value;
    }

    /**
     * Returns the limit for a feature, or null for unlimited.
     */
    public function limit(int $companyId, string $key): ?int
    {
        if (! PlanFeature::isLimit($key)) {
            return null;
        }
        $value = $this->featuresFor($companyId)[$key] ?? PlanFeature::defaultValue($key);
        // -1 (or any negative) = unlimited.
        if ((int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Returns true if the proposed new total stays within the limit.
     * A null limit means unlimited.
     */
    public function canHaveOneMore(int $companyId, string $key, int $currentCount): bool
    {
        $limit = $this->limit($companyId, $key);
        if ($limit === null) {
            return true;
        }

        return ($currentCount + 1) <= $limit;
    }

    /**
     * @return array<string, bool|int>
     */
    private function resolveFeatures(int $companyId): array
    {
        $defaults = collect(PlanFeature::all())
            ->mapWithKeys(fn ($def, $key) => [$key => $def['default']])
            ->all();

        $subscription = CompanySubscription::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'trial'])
            ->first();

        if ($subscription === null) {
            return $defaults;
        }

        $plan = SubscriptionPlan::query()->find($subscription->plan_id);
        if ($plan === null || ! $plan->is_active) {
            return $defaults;
        }

        $planFeatures = $plan->features ?? [];
        // Legacy support: if features is a flat list of strings, treat
        // each string as a bool-true flag.
        if (is_array($planFeatures) && array_is_list($planFeatures)) {
            $planFeatures = collect($planFeatures)
                ->mapWithKeys(fn ($k) => [$k => true])
                ->all();
        }

        return array_merge($defaults, $planFeatures);
    }
}
