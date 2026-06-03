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
 * Phase 6.2 — detail (show-by-id) ownership. An operator may open ONLY their
 * own company's rows by id; another company's row returns 404 (no by-id back
 * door around the scoped lists). Super-admin opens anything.
 */
class OperatorDetailOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
        $this->companyA = Company::query()->create(['name' => 'Own A', 'type' => 'operator']);
        $this->companyB = Company::query()->create(['name' => 'Other B', 'type' => 'operator']);

        $this->operator = User::factory()->create();
        $this->operator->companies()->attach($this->companyA->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);
        $this->operator = $this->operator->fresh();
    }

    public function test_operator_opens_own_company_but_not_another(): void
    {
        Sanctum::actingAs($this->operator);

        $this->getJson("/api/platform-admin/companies/{$this->companyA->id}")->assertOk();
        $this->getJson("/api/platform-admin/companies/{$this->companyB->id}")->assertStatus(404);
    }

    public function test_operator_opens_own_staff_but_not_another_companys_user(): void
    {
        $myStaff = User::factory()->create();
        $myStaff->companies()->attach($this->companyA->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);

        $otherStaff = User::factory()->create();
        $otherStaff->companies()->attach($this->companyB->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);

        Sanctum::actingAs($this->operator);
        $this->getJson("/api/platform-admin/users/{$myStaff->id}")->assertOk();
        $this->getJson("/api/platform-admin/users/{$otherStaff->id}")->assertStatus(404);
    }

    public function test_operator_opens_own_booking_but_not_another(): void
    {
        $buyer = User::factory()->create();
        $mine = Order::query()->create([
            'order_number' => 'OWN-'.str()->uuid(), 'user_id' => $buyer->id,
            'company_id' => $this->companyA->id, 'status' => 'paid', 'currency' => 'USD', 'total' => 50,
        ]);
        $theirs = Order::query()->create([
            'order_number' => 'OTH-'.str()->uuid(), 'user_id' => $buyer->id,
            'company_id' => $this->companyB->id, 'status' => 'paid', 'currency' => 'USD', 'total' => 60,
        ]);

        Sanctum::actingAs($this->operator);
        $this->getJson("/api/platform-admin/bookings/{$mine->id}")->assertOk();
        $this->getJson("/api/platform-admin/bookings/{$theirs->id}")->assertStatus(404);
    }

    public function test_super_admin_opens_any_company(): void
    {
        $super = User::factory()->create();
        $super->companies()->attach($this->companyA->id, ['role_id' => Role::query()->where('name', 'super_admin')->value('id')]);
        Sanctum::actingAs($super->fresh());

        $this->getJson("/api/platform-admin/companies/{$this->companyB->id}")->assertOk();
    }
}
