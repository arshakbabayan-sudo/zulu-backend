<?php

namespace App\Services\Infrastructure;

use App\Models\Company;
use App\Models\CompanyCountryPermission;

class GeoRestrictionService
{
    /**
     * Validate that a company is allowed to sell a service in a given country.
     * Returns null if allowed, returns error message string if not allowed.
     *
     * Rules (in order):
     *   1. Airlines selling flights — always allowed (back-compat).
     *   2. Company has no registered country — skip restriction (back-compat).
     *   3. Service country empty — skip restriction (back-compat).
     *   4. Service country == company's registered country — allowed.
     *   5. Active CompanyCountryPermission row matches by name OR code — allowed.
     *   6. Otherwise — denied.
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

        $companyCountry = strtolower(trim($company->country ?? ''));
        $serviceCountryLc = strtolower(trim($serviceCountry));

        // If company has no country set, skip restriction (existing behavior).
        if (empty($companyCountry)) {
            return null;
        }

        // If service country is not set, skip restriction (existing behavior).
        if (empty($serviceCountryLc)) {
            return null;
        }

        // Home country always allowed.
        if ($companyCountry === $serviceCountryLc) {
            return null;
        }

        // Check explicit per-country grants.
        $hasGrant = CompanyCountryPermission::query()
            ->where('company_id', $company->id)
            ->where('status', CompanyCountryPermission::STATUS_ACTIVE)
            ->where(function ($q) use ($serviceCountryLc) {
                $q->whereRaw('LOWER(country_name) = ?', [$serviceCountryLc])
                  ->orWhereRaw('LOWER(country_code) = ?', [$serviceCountryLc]);
            })
            ->exists();

        if ($hasGrant) {
            return null;
        }

        return 'You can only sell services from your registered country ('
            .strtoupper($company->country).'). '
            .'This service is in '.strtoupper($serviceCountry).'. '
            .'Contact platform admin to request a multi-country license.';
    }
}
