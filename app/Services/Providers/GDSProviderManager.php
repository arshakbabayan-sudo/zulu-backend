<?php

namespace App\Services\Providers;

use Illuminate\Support\Collection;

class GDSProviderManager
{
    protected array $providers = [];

    /**
     * Register a new provider (e.g., Amadeus, Sabre).
     */
    public function registerProvider(string $name, ProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    /**
     * Get a specific provider by name.
     */
    public function getProvider(string $name): ?ProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Search across all registered providers and aggregate results.
     */
    public function searchAll(array $params): Collection
    {
        $allResults = collect();

        foreach ($this->providers as $name => $provider) {
            try {
                $results = $provider->search($params);
                foreach ($results as $result) {
                    $result['provider'] = $name;
                    $allResults->push($result);
                }
            } catch (\Exception $e) {
                // Log error and continue with other providers
                \Log::error("Provider $name search error: " . $e->getMessage());
            }
        }

        return $allResults;
    }
}
