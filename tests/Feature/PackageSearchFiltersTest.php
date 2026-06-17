<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Package;
use App\Services\Packages\PackageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the price / currency / availability filters added to the public
 * package search (the frontend slider was previously visual-only). Star and
 * travel-date filters are intentionally out of scope — they need component-level
 * data that belongs to the dynamic-pricing work (§13).
 */
class PackageSearchFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function search(array $filters): array
    {
        return app(PackageSearchService::class)->search($filters);
    }

    public function test_price_min_excludes_cheaper_packages(): void
    {
        $this->makePackage(['base_price' => 100]);
        $this->makePackage(['base_price' => 500]);
        $this->makePackage(['base_price' => 1000]);

        $result = $this->search(['price_min' => 400]);

        $this->assertSame(2, $result['meta']['total']);
        foreach ($result['data'] as $row) {
            $this->assertGreaterThanOrEqual(400, (float) $row['base_price']);
        }
    }

    public function test_price_max_excludes_pricier_packages(): void
    {
        $this->makePackage(['base_price' => 100]);
        $this->makePackage(['base_price' => 500]);
        $this->makePackage(['base_price' => 1000]);

        $result = $this->search(['price_max' => 600]);

        $this->assertSame(2, $result['meta']['total']);
        foreach ($result['data'] as $row) {
            $this->assertLessThanOrEqual(600, (float) $row['base_price']);
        }
    }

    public function test_price_range_narrows_to_band(): void
    {
        $this->makePackage(['base_price' => 100]);
        $this->makePackage(['base_price' => 500]);
        $this->makePackage(['base_price' => 1000]);

        $result = $this->search(['price_min' => 200, 'price_max' => 800]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('500.00', (string) $result['data'][0]['base_price']);
    }

    public function test_currency_filter_isolates_one_currency(): void
    {
        $this->makePackage(['base_price' => 500, 'currency' => 'USD']);
        $this->makePackage(['base_price' => 500, 'currency' => 'AMD']);

        $result = $this->search(['currency' => 'usd']); // case-insensitive

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('USD', $result['data'][0]['currency']);
    }

    public function test_bookable_only_returns_only_bookable_packages(): void
    {
        $this->makePackage(['is_bookable' => true]);
        $this->makePackage(['is_bookable' => false]);

        $all = $this->search([]);
        $this->assertSame(2, $all['meta']['total']);

        $bookable = $this->search(['bookable_only' => true]);
        $this->assertSame(1, $bookable['meta']['total']);
        $this->assertTrue((bool) $bookable['data'][0]['is_bookable']);
    }

    public function test_empty_price_strings_are_ignored(): void
    {
        $this->makePackage(['base_price' => 100]);
        $this->makePackage(['base_price' => 1000]);

        // Blank slider values must not filter anything out.
        $result = $this->search(['price_min' => '', 'price_max' => '']);

        $this->assertSame(2, $result['meta']['total']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makePackage(array $attrs = []): Package
    {
        $company = Company::create([
            'name' => 'Operator Co',
            'type' => 'operator',
            'slug' => 'operator-co-'.uniqid(),
        ]);

        $offer = Offer::create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Trip',
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        return Package::create(array_merge([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Trip',
            'destination_country' => 'Armenia',
            'destination_city' => 'Yerevan',
            'duration_days' => 5,
            'base_price' => 500,
            'display_price_mode' => 'total',
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => true,
            'status' => 'active',
        ], $attrs));
    }
}
