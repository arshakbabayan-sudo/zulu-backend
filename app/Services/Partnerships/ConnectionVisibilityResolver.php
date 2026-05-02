<?php

namespace App\Services\Partnerships;

use App\Models\Company;
use App\Models\Connection;
use Illuminate\Support\Collection;

/**
 * PART 18 §4.3 — Visibility resolver.
 *
 * Given a viewer Seller (acting as reseller), determines which supplier
 * Sellers' services are visible to them through active partner connections,
 * and applies share_scope + territorial_scope filtering.
 *
 * No transitive resolution: A→B→C does not propagate. Only direct connections
 * between the viewer and the supplier are considered.
 */
class ConnectionVisibilityResolver
{
    /**
     * All active connections where $viewer is on the receiving (reseller) side.
     *
     * @return Collection<int, Connection>
     */
    public function activeIncomingConnections(Company $viewer): Collection
    {
        return Connection::query()
            ->where('status', 'active')
            ->where(function ($q) use ($viewer): void {
                // a_to_b: B side receives → viewer is seller_b
                $q->where(function ($qq) use ($viewer): void {
                    $qq->where('direction', 'a_to_b')
                        ->where('seller_b_company_id', $viewer->id);
                });
                // b_to_a: A side receives → viewer is seller_a
                $q->orWhere(function ($qq) use ($viewer): void {
                    $qq->where('direction', 'b_to_a')
                        ->where('seller_a_company_id', $viewer->id);
                });
                // both: viewer is on either side
                $q->orWhere(function ($qq) use ($viewer): void {
                    $qq->where('direction', 'both')
                        ->where(function ($qqq) use ($viewer): void {
                            $qqq->where('seller_a_company_id', $viewer->id)
                                ->orWhere('seller_b_company_id', $viewer->id);
                        });
                });
            })
            ->where(function ($q): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', now());
            })
            ->where('effective_from', '<=', now())
            ->get();
    }

    /**
     * Find the active connection (if any) through which $supplier feeds services to $viewer.
     */
    public function applicableConnection(Company $viewer, Company $supplier): ?Connection
    {
        return $this->activeIncomingConnections($viewer)
            ->first(fn (Connection $c) => $c->flowsFrom($supplier->id, $viewer->id));
    }

    /**
     * Returns true if a specific service from $supplier is visible to $viewer.
     *
     * @param  string  $serviceType  one of: flight, hotel, transfer, car, excursion, visa, insurance, package
     * @param  int|string|null  $serviceId  the supplier's service row id (used when share_scope.type == 'services')
     * @param  array<int, string>|null  $serviceCountryCodes  ISO country codes the service relates to (for territorial filtering)
     */
    public function isServiceVisible(
        Company $viewer,
        Company $supplier,
        string $serviceType,
        $serviceId = null,
        ?array $serviceCountryCodes = null,
    ): bool {
        $connection = $this->applicableConnection($viewer, $supplier);
        if ($connection === null) {
            return false;
        }

        if (! $this->shareScopeAllows($connection, $serviceType, $serviceId)) {
            return false;
        }

        return $this->territorialScopeAllows($connection, $serviceCountryCodes);
    }

    /**
     * Returns the set of supplier company IDs visible to $viewer (through any active connection).
     *
     * @return array<int, int>
     */
    public function visibleSupplierIds(Company $viewer): array
    {
        return $this->activeIncomingConnections($viewer)
            ->map(fn (Connection $c) => $c->counterpartyOf($viewer->id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * For a given (viewer, supplier) pair, lists the service types granted by the connection.
     * Returns null when scope is 'all' or 'services' (caller must inspect items individually).
     *
     * @return array<int, string>|null null = not narrowable to type list
     */
    public function visibleServiceTypes(Company $viewer, Company $supplier): ?array
    {
        $connection = $this->applicableConnection($viewer, $supplier);
        if ($connection === null) {
            return [];
        }

        $scope = $connection->share_scope ?? ['type' => 'all', 'list' => []];
        $type = $scope['type'] ?? 'all';

        if ($type === 'categories') {
            return array_values(array_filter($scope['list'] ?? [], fn ($x) => is_string($x) && $x !== ''));
        }

        return null; // 'all' or 'services' — caller must check individual items
    }

    private function shareScopeAllows(Connection $connection, string $serviceType, $serviceId): bool
    {
        $scope = $connection->share_scope ?? ['type' => 'all', 'list' => []];
        $type = $scope['type'] ?? 'all';
        $list = is_array($scope['list'] ?? null) ? $scope['list'] : [];

        if ($type === 'all') {
            return true;
        }

        if ($type === 'categories') {
            return in_array($serviceType, $list, true);
        }

        if ($type === 'services') {
            if ($serviceId === null) {
                return false;
            }

            // Service-level list may be stored as ints or as "type:id" strings.
            // Accept both raw IDs and the prefixed form for forward-compat.
            $candidates = [$serviceId, (string) $serviceId, $serviceType.':'.$serviceId];

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $list, true)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * @param  array<int, string>|null  $serviceCountryCodes
     */
    private function territorialScopeAllows(Connection $connection, ?array $serviceCountryCodes): bool
    {
        $scope = $connection->territorial_scope;

        if (! is_array($scope) || $scope === []) {
            return true; // no restriction
        }

        if ($serviceCountryCodes === null || $serviceCountryCodes === []) {
            return true; // unknown service location — be permissive (caller can refine)
        }

        $allowed = array_map(fn ($c) => strtoupper((string) $c), $scope);
        $serviceCountries = array_map(fn ($c) => strtoupper((string) $c), $serviceCountryCodes);

        return array_intersect($allowed, $serviceCountries) !== [];
    }
}
