<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryStayModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_type_stay_is_accepted(): void
    {
        // Validation passes (200/empty result rather than 422).
        $resp = $this->getJson('/api/discovery/search?module_type=stay');
        $this->assertNotSame(422, $resp->getStatusCode());
    }

    public function test_accommodation_type_param_is_accepted(): void
    {
        $resp = $this->getJson('/api/discovery/search?module_type=hotel&accommodation_type=apartment');
        $this->assertNotSame(422, $resp->getStatusCode());
    }

    public function test_accommodation_type_rejects_unknown_value(): void
    {
        $resp = $this->getJson('/api/discovery/search?module_type=hotel&accommodation_type=castle');
        $resp->assertStatus(422);
    }
}
