<?php

namespace Tests\Unit\Services\Infrastructure;

use App\Models\Company;
use App\Models\CompanyCountryPermission;
use App\Services\Infrastructure\GeoRestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoRestrictionServiceTest extends TestCase
{
    use RefreshDatabase;

    private GeoRestrictionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeoRestrictionService;
    }

    private function makeCompany(array $attrs = []): Company
    {
        return Company::query()->create(array_merge([
            'name' => 'Test Co',
            'type' => 'agency',
            'country' => 'Armenia',
            'is_airline' => false,
        ], $attrs));
    }

    public function test_airline_can_sell_flights_from_any_country(): void
    {
        $airline = $this->makeCompany(['country' => 'Armenia', 'is_airline' => true]);

        $this->assertNull($this->service->validateServiceCountry($airline, 'France', 'flight'));
        $this->assertNull($this->service->validateServiceCountry($airline, 'Egypt', 'flight'));
    }

    public function test_airline_still_restricted_for_non_flight_services(): void
    {
        $airline = $this->makeCompany(['country' => 'Armenia', 'is_airline' => true]);

        $this->assertNotNull($this->service->validateServiceCountry($airline, 'France', 'hotel'));
    }

    public function test_company_with_no_country_skips_restriction(): void
    {
        $co = $this->makeCompany(['country' => null]);

        $this->assertNull($this->service->validateServiceCountry($co, 'France'));
    }

    public function test_empty_service_country_skips_restriction(): void
    {
        $co = $this->makeCompany(['country' => 'Armenia']);

        $this->assertNull($this->service->validateServiceCountry($co, ''));
        $this->assertNull($this->service->validateServiceCountry($co, '   '));
    }

    public function test_home_country_always_allowed_case_insensitive(): void
    {
        $co = $this->makeCompany(['country' => 'Armenia']);

        $this->assertNull($this->service->validateServiceCountry($co, 'Armenia'));
        $this->assertNull($this->service->validateServiceCountry($co, 'ARMENIA'));
        $this->assertNull($this->service->validateServiceCountry($co, 'armenia'));
        $this->assertNull($this->service->validateServiceCountry($co, ' Armenia '));
    }

    public function test_active_grant_by_country_name_allows(): void
    {
        $co = $this->makeCompany(['country' => 'Armenia']);
        CompanyCountryPermission::query()->create([
            'company_id' => $co->id,
            'country_code' => 'FR',
            'country_name' => 'France',
            'status' => CompanyCountryPermission::STATUS_ACTIVE,
        ]);

        $this->assertNull($this->service->validateServiceCountry($co, 'France'));
        $this->assertNull($this->service->validateServiceCountry($co, 'france'));
    }

    public function test_active_grant_by_country_code_allows(): void
    {
        $co = $this->makeCompany(['country' => 'Armenia']);
        CompanyCountryPermission::query()->create([
            'company_id' => $co->id,
            'country_code' => 'FR',
            'country_name' => 'France',
            'status' => CompanyCountryPermission::STATUS_ACTIVE,
        ]);

        $this->assertNull($this->service->validateServiceCountry($co, 'FR'));
        $this->assertNull($this->service->validateServiceCountry($co, 'fr'));
    }

    public function test_revoked_grant_does_not_allow(): void
    {
        $co = $this->makeCompany(['country' => 'Armenia']);
        CompanyCountryPermission::query()->create([
            'company_id' => $co->id,
            'country_code' => 'FR',
            'country_name' => 'France',
            'status' => CompanyCountryPermission::STATUS_REVOKED,
        ]);

        $msg = $this->service->validateServiceCountry($co, 'France');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('FRANCE', $msg);
    }

    public function test_no_grant_returns_descriptive_error_with_country_codes(): void
    {
        $co = $this->makeCompany(['country' => 'Armenia']);

        $msg = $this->service->validateServiceCountry($co, 'France');

        $this->assertNotNull($msg);
        $this->assertStringContainsString('ARMENIA', $msg);
        $this->assertStringContainsString('FRANCE', $msg);
        $this->assertStringContainsString('platform admin', $msg);
    }

    public function test_grant_for_other_company_does_not_leak(): void
    {
        $co1 = $this->makeCompany(['country' => 'Armenia']);
        $co2 = $this->makeCompany(['country' => 'Armenia']);

        CompanyCountryPermission::query()->create([
            'company_id' => $co2->id,
            'country_code' => 'FR',
            'country_name' => 'France',
            'status' => CompanyCountryPermission::STATUS_ACTIVE,
        ]);

        $this->assertNull($this->service->validateServiceCountry($co2, 'France'));
        $this->assertNotNull($this->service->validateServiceCountry($co1, 'France'));
    }
}
