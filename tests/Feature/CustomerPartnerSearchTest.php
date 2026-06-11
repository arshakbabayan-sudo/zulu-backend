<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/customer/partner-search — the Add-partner picker (roadmap §9):
 * active seller companies a B2C customer can add to "My partners".
 */
class CustomerPartnerSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seller(string $name, string $type = 'agency', string $country = 'Armenia', array $extra = []): Company
    {
        return Company::query()->create(array_merge([
            'name' => $name,
            'type' => $type,
            'country' => $country,
            'is_seller' => true,
            'governance_status' => 'active',
        ], $extra));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/customer/partner-search?country=Armenia')->assertStatus(401);
    }

    public function test_finds_active_sellers_by_case_insensitive_partial_name(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $agency = $this->seller('Aram Travel Agency', 'agency');
        $operator = $this->seller('Ararat Tour Operator', 'operator');
        $this->seller('Vardan Voyages', 'agency');

        $res = $this->getJson('/api/customer/partner-search?country=Armenia&q=ARA')->assertOk();

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($agency->id));
        $this->assertTrue($ids->contains($operator->id));
        $this->assertCount(2, $ids);

        $row = collect($res->json('data'))->firstWhere('id', $agency->id);
        $this->assertSame('Aram Travel Agency', $row['name']);
        $this->assertSame('agency', $row['type']);
        $this->assertSame('Armenia', $row['country']);
        $this->assertFalse($row['already_added']);
    }

    public function test_excludes_suspended_non_seller_archived_and_other_country(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $visible = $this->seller('Aram Visible');
        $this->seller('Aram Suspended', 'agency', 'Armenia', ['governance_status' => 'suspended']);
        $this->seller('Aram NotSeller', 'agency', 'Armenia', ['is_seller' => false]);
        $this->seller('Aram Archived', 'agency', 'Armenia', ['archived_at' => now()]);
        $this->seller('Aram Abroad', 'agency', 'Georgia');

        $res = $this->getJson('/api/customer/partner-search?country=Armenia&q=aram')->assertOk();

        $this->assertSame([$visible->id], collect($res->json('data'))->pluck('id')->all());
    }

    public function test_single_character_query_returns_empty(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->seller('Aram Travel Agency');

        $this->getJson('/api/customer/partner-search?country=Armenia&q=a')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_already_added_flags_companies_in_callers_partner_list(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $added = $this->seller('Aram Added');
        $notAdded = $this->seller('Aram Fresh');

        $this->postJson('/api/customer/partners', ['partner_company_id' => $added->id, 'country' => 'Armenia'])
            ->assertCreated();

        $res = $this->getJson('/api/customer/partner-search?country=Armenia&q=aram')->assertOk();
        $rows = collect($res->json('data'))->keyBy('id');

        $this->assertTrue($rows[$added->id]['already_added']);
        $this->assertFalse($rows[$notAdded->id]['already_added']);
    }
}
