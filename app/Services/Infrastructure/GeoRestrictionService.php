<?php

namespace App\Services\Infrastructure;

use App\Models\Company;

class GeoRestrictionService
{
    /**
     * Validate that a company is allowed to sell a service in a given country.
     * Returns null if allowed, returns error message string if not allowed.
     */
    public function validateServiceCountry(
        Company $company,
        string $serviceCountry,
        string $serviceType = 'general'
    ): ?string {
        // Airlines can sell flights from any country
        if ($serviceType === 'flight' && $company->is_airline) {
            return null;
        }

        // Normalize country codes for comparison (uppercase)
        $companyCountry = strtolower(trim($company->country ?? ''));
        $serviceCountry = strtolower(trim($serviceCountry));

        // If company has no country set, skip restriction
        if (empty($companyCountry)) {
            return null;
        }

        // If service country is not set, skip restriction
        if (empty($serviceCountry)) {
            return null;
        }

        if ($companyCountry !== $serviceCountry) {
            return 'You can only sell services from your registered country ('
                .strtoupper($company->country).'). '
                .'This service is in '.strtoupper($serviceCountry).'.';
        }

        return null;
    }
}

