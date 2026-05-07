<?php

namespace App\Http\Resources\Api\Concerns;

use App\Models\Location;

/**
 * Walks a Location's parent chain to derive city / region / country labels.
 *
 * The legacy `country` / `region_or_state` / `city` text columns were dropped
 * from product tables (hotels, cars, transfers, flights, excursions, visas)
 * after the `location_id` FK cutover. Resources that still need to expose
 * those labels — for backward compatibility with admin tables, customer
 * listings, voucher PDFs, etc. — derive them by walking the location subtree.
 */
trait DerivesLocationLabels
{
    /**
     * @return array{city: ?string, region: ?string, country: ?string}
     */
    protected function deriveLocationLabels(?Location $location): array
    {
        $labels = ['city' => null, 'region' => null, 'country' => null];

        if ($location === null) {
            return $labels;
        }

        // Walk parent_id chain directly. Location::ancestors() relies on a
        // numeric `path` column, but our seeders write slug-based paths, so
        // ancestors() drops country roots for cities/regions.
        $cursor = $location;
        $hops = 0;
        while ($cursor !== null && $hops < 6) {
            if ($cursor->type === Location::TYPE_CITY && $labels['city'] === null) {
                $labels['city'] = $cursor->name;
            } elseif ($cursor->type === Location::TYPE_REGION && $labels['region'] === null) {
                $labels['region'] = $cursor->name;
            } elseif ($cursor->type === Location::TYPE_COUNTRY && $labels['country'] === null) {
                $labels['country'] = $cursor->name;
            }
            $cursor = $cursor->parent_id ? Location::find($cursor->parent_id) : null;
            $hops++;
        }

        return $labels;
    }
}
