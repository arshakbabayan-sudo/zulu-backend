<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerPartner;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B2C customer "My partners" CRUD (seller-attribution Phase 1 foundation).
 * See docs/blueprints/seller-attribution-architecture-2026-06-04.md.
 */
class CustomerPartnerTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::query()->create(['name' => $name, 'type' => 'agency']);
    }

    public function test_add_list_and_rank(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $a = $this->company('Aram Agent');
        $b = $this->company('Vardan Agent');

        $this->postJson('/api/customer/partners', ['partner_company_id' => $a->id, 'country' => 'Armenia'])
            ->assertCreated()->assertJsonPath('data.rank', 1);
        $this->postJson('/api/customer/partners', ['partner_company_id' => $b->id, 'country' => 'Armenia'])
            ->assertCreated()->assertJsonPath('data.rank', 2);

        $this->getJson('/api/customer/partners?country=Armenia')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.partner.id', $a->id)
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.1.partner.id', $b->id);
    }

    public function test_rejects_duplicate_partner_per_country(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $a = $this->company('Aram');

        $this->postJson('/api/customer/partners', ['partner_company_id' => $a->id, 'country' => 'Armenia'])->assertCreated();
        $this->postJson('/api/customer/partners', ['partner_company_id' => $a->id, 'country' => 'Armenia'])->assertStatus(422);
    }

    public function test_enforces_max_per_country_but_country_is_independent(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        for ($i = 0; $i < CustomerPartner::MAX_PER_COUNTRY; $i++) {
            $this->postJson('/api/customer/partners', [
                'partner_company_id' => $this->company("Co {$i}")->id,
                'country' => 'Armenia',
            ])->assertCreated();
        }

        $overflow = $this->company('Overflow');
        $this->postJson('/api/customer/partners', ['partner_company_id' => $overflow->id, 'country' => 'Armenia'])->assertStatus(422);
        // A different country has its own independent budget.
        $this->postJson('/api/customer/partners', ['partner_company_id' => $overflow->id, 'country' => 'Georgia'])->assertCreated();
    }

    public function test_reorder_sets_ranks_by_order(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $ids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $ids[] = $this->postJson('/api/customer/partners', [
                'partner_company_id' => $this->company($name)->id,
                'country' => 'Armenia',
            ])->json('data.id');
        }

        $this->putJson('/api/customer/partners/reorder', [
            'country' => 'Armenia',
            'ordered_ids' => array_reverse($ids),
        ])->assertOk();

        $this->getJson('/api/customer/partners?country=Armenia')
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.2.id', $ids[0]);
    }

    public function test_destroy_resequences_and_guards_ownership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $idA = $this->postJson('/api/customer/partners', ['partner_company_id' => $this->company('A')->id, 'country' => 'Armenia'])->json('data.id');
        $idB = $this->postJson('/api/customer/partners', ['partner_company_id' => $this->company('B')->id, 'country' => 'Armenia'])->json('data.id');

        $this->deleteJson("/api/customer/partners/{$idA}")->assertOk();
        $this->getJson('/api/customer/partners?country=Armenia')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $idB)
            ->assertJsonPath('data.0.rank', 1);

        // Another user cannot delete this customer's partner.
        Sanctum::actingAs($other, ['*']);
        $this->deleteJson("/api/customer/partners/{$idB}")->assertStatus(404);
    }

    public function test_seller_options_lists_market_and_partner_agent_default(): void
    {
        $operator = Company::query()->create(['name' => 'Operator X', 'type' => 'operator', 'country' => 'Armenia']);
        $agent = Company::query()->create(['name' => 'Aram Agent', 'type' => 'agency', 'country' => 'Armenia']);
        $offer = Offer::query()->create([
            'source_lang' => 'en',
            'company_id' => $operator->id,
            'type' => 'hotel',
            'title' => 'Test Hotel Offer',
            'price' => 90,
            'currency' => 'USD',
            'status' => 'published',
        ]);

        Sanctum::actingAs(User::factory()->create(), ['*']);
        // Ani prefers the agent for Armenia.
        $this->postJson('/api/customer/partners', ['partner_company_id' => $agent->id, 'country' => 'Armenia'])->assertCreated();

        $res = $this->getJson("/api/offers/{$offer->id}/seller-options")->assertOk();

        $res->assertJsonPath('data.market.company_id', $operator->id)
            ->assertJsonPath('data.options.0.seller_company_id', $agent->id)
            ->assertJsonPath('data.options.0.is_agent', true)
            ->assertJsonPath('data.options.0.is_default', true)
            ->assertJsonPath('data.default_company_id', $agent->id);

        // Operator-direct is always available as a fallback option.
        $ids = collect($res->json('data.options'))->pluck('seller_company_id');
        $this->assertTrue($ids->contains($operator->id));
    }

    public function test_resolve_chosen_agent_attributes_or_rejects(): void
    {
        $customer = User::factory()->create();
        $supplier = Company::query()->create(['name' => 'Op', 'type' => 'operator']);
        $agent = Company::query()->create(['name' => 'Ag', 'type' => 'agency']);
        CustomerPartner::query()->create([
            'customer_user_id' => $customer->id,
            'partner_company_id' => $agent->id,
            'country' => 'Armenia',
            'rank' => 1,
        ]);

        // No seller / the supplier itself → direct purchase (null agent).
        $this->assertNull(CustomerPartner::resolveChosenAgent($customer->id, null, $supplier->id));
        $this->assertNull(CustomerPartner::resolveChosenAgent($customer->id, $supplier->id, $supplier->id));
        // A partner-agent → the sale is attributed to that agent.
        $this->assertSame($agent->id, CustomerPartner::resolveChosenAgent($customer->id, $agent->id, $supplier->id));

        // A company that is NOT one of the customer's partners → rejected (anti-spoof).
        $stranger = Company::query()->create(['name' => 'X', 'type' => 'agency']);
        $this->expectException(ValidationException::class);
        CustomerPartner::resolveChosenAgent($customer->id, $stranger->id, $supplier->id);
    }
}
