<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRM Customers = the company's OWN buyers (people who placed an order with the
 * company), scoped to the caller's companies. Operator sees only their buyers;
 * super sees every buyer. NOT the platform B2C registry.
 */
class CrmCustomersScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    private function buyerWithOrder(int $companyId, string $email): User
    {
        $buyer = User::factory()->create(['email' => $email]);
        Order::query()->create([
            'order_number' => 'CC-'.str()->uuid(), 'user_id' => $buyer->id,
            'company_id' => $companyId, 'status' => 'paid', 'currency' => 'USD', 'total' => 80,
        ]);

        return $buyer;
    }

    public function test_operator_sees_only_their_own_buyers(): void
    {
        $companyA = Company::query()->create(['name' => 'Buyers A', 'type' => 'operator']);
        $companyB = Company::query()->create(['name' => 'Buyers B', 'type' => 'operator']);

        $mine = $this->buyerWithOrder($companyA->id, 'mybuyer@example.test');
        $theirs = $this->buyerWithOrder($companyB->id, 'otherbuyer@example.test');

        $operator = User::factory()->create();
        $operator->companies()->attach($companyA->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);

        Sanctum::actingAs($operator->fresh());
        $res = $this->getJson('/api/platform-admin/crm/customers')->assertOk();
        $emails = collect($res->json('data'))->pluck('email')->all();

        $this->assertContains('mybuyer@example.test', $emails);
        $this->assertNotContains('otherbuyer@example.test', $emails);
        // bookings_count is their order count with this company.
        $row = collect($res->json('data'))->firstWhere('email', 'mybuyer@example.test');
        $this->assertSame(1, $row['bookings_count']);
    }

    public function test_super_sees_all_buyers(): void
    {
        $companyA = Company::query()->create(['name' => 'SA', 'type' => 'operator']);
        $companyB = Company::query()->create(['name' => 'SB', 'type' => 'operator']);
        $this->buyerWithOrder($companyA->id, 'a-buyer@example.test');
        $this->buyerWithOrder($companyB->id, 'b-buyer@example.test');

        $super = User::factory()->create();
        $super->companies()->attach($companyA->id, ['role_id' => Role::query()->where('name', 'super_admin')->value('id')]);

        Sanctum::actingAs($super->fresh());
        $res = $this->getJson('/api/platform-admin/crm/customers')->assertOk();
        $emails = collect($res->json('data'))->pluck('email')->all();

        $this->assertContains('a-buyer@example.test', $emails);
        $this->assertContains('b-buyer@example.test', $emails);
    }
}
