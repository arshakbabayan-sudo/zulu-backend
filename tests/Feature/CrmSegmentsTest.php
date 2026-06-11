<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmSegment;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRM Segments (2026-06-12, roadmap §4) — dynamic rule evaluation against the
 * company's buyers, static member management, and the owner-only write gate.
 */
class CrmSegmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin', 'company_viewer'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    private function roleId(string $name): int
    {
        return (int) Role::query()->where('name', $name)->value('id');
    }

    private function makeOwner(Company $company): User
    {
        $owner = User::factory()->create();
        $owner->companies()->attach($company->id, ['role_id' => $this->roleId('company_admin')]);

        return $owner;
    }

    private function order(User $buyer, Company $company, float $total, ?string $createdAt = null): Order
    {
        $order = Order::query()->create([
            'order_number' => 'SEG-'.Str::uuid(),
            'user_id' => $buyer->id,
            'company_id' => $company->id,
            'status' => 'paid',
            'currency' => 'USD',
            'total' => $total,
        ]);
        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        return $order;
    }

    public function test_dynamic_segment_min_bookings_rule(): void
    {
        $company = Company::query()->create(['name' => 'Seg Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);

        $loyal = User::factory()->create();
        $casual = User::factory()->create();
        $this->order($loyal, $company, 100);
        $this->order($loyal, $company, 150);
        $this->order($casual, $company, 80);

        Sanctum::actingAs($owner->fresh());

        $created = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Repeat bookers',
            'type' => 'dynamic',
            'rules' => ['min_bookings' => 2],
        ])->assertCreated()->json();

        $this->assertSame(1, $created['data']['contacts_count']);

        $contacts = $this->getJson('/api/platform-admin/crm/segments/'.$created['data']['id'].'/contacts')
            ->assertOk()->json();
        $this->assertCount(1, $contacts['data']);
        $this->assertSame($loyal->id, $contacts['data'][0]['id']);
        $this->assertSame(2, $contacts['data'][0]['bookings_count']);
    }

    public function test_dynamic_segment_total_spent_and_activity_rules(): void
    {
        $company = Company::query()->create(['name' => 'Spend Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);

        $vip = User::factory()->create();
        $small = User::factory()->create();
        $lapsed = User::factory()->create();
        $this->order($vip, $company, 900);
        $this->order($vip, $company, 600);
        $this->order($small, $company, 50);
        $this->order($lapsed, $company, 2000, now()->subMonths(10)->toDateTimeString());

        Sanctum::actingAs($owner->fresh());

        // Spent ≥ 1000 → vip + lapsed qualify.
        $bigSpenders = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'VIP', 'type' => 'dynamic', 'rules' => ['min_total_spent' => 1000],
        ])->assertCreated()->json();
        $this->assertSame(2, $bigSpenders['data']['contacts_count']);

        // No purchase in the last 6 months → only the lapsed buyer.
        $lapsedSeg = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Lapsed', 'type' => 'dynamic', 'rules' => ['inactive_months' => 6],
        ])->assertCreated()->json();
        $this->assertSame(1, $lapsedSeg['data']['contacts_count']);

        // Active in the last 6 months → vip + small.
        $activeSeg = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Active', 'type' => 'dynamic', 'rules' => ['active_months' => 6],
        ])->assertCreated()->json();
        $this->assertSame(2, $activeSeg['data']['contacts_count']);
    }

    public function test_static_segment_member_management(): void
    {
        $company = Company::query()->create(['name' => 'Static Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);
        // Members must be the company's OWN buyers (tenant safety), so the
        // customer needs at least one order with this company.
        $customer = User::factory()->create();
        $this->order($customer, $company, 120);

        Sanctum::actingAs($owner->fresh());

        $seg = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Honeymoon interest', 'type' => 'static',
        ])->assertCreated()->json();
        $segId = $seg['data']['id'];
        $this->assertSame(0, $seg['data']['contacts_count']);

        $this->postJson("/api/platform-admin/crm/segments/{$segId}/members", ['user_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('data.contacts_count', 1);

        $contacts = $this->getJson("/api/platform-admin/crm/segments/{$segId}/contacts")->assertOk()->json();
        $this->assertSame($customer->id, $contacts['data'][0]['id']);

        $this->deleteJson("/api/platform-admin/crm/segments/{$segId}/members/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.contacts_count', 0);
    }

    public function test_member_must_be_a_buyer_of_the_segment_company(): void
    {
        $mine = Company::query()->create(['name' => 'Buyers Co', 'type' => 'operator']);
        $other = Company::query()->create(['name' => 'Other Buyers Co', 'type' => 'operator']);
        $owner = $this->makeOwner($mine);
        // A platform user with NO orders at all, and a buyer of ANOTHER company —
        // neither may enter my static segment (cross-tenant PII guard: the
        // contacts read returns name+email, so arbitrary ids would let a tenant
        // harvest the whole users table).
        $stranger = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $this->order($otherBuyer, $other, 300);

        Sanctum::actingAs($owner->fresh());

        $seg = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Guarded', 'type' => 'static',
        ])->assertCreated()->json();
        $segId = $seg['data']['id'];

        $this->postJson("/api/platform-admin/crm/segments/{$segId}/members", ['user_id' => $stranger->id])
            ->assertStatus(422);
        $this->postJson("/api/platform-admin/crm/segments/{$segId}/members", ['user_id' => $otherBuyer->id])
            ->assertStatus(422);

        $this->assertSame(
            0,
            (int) $this->getJson("/api/platform-admin/crm/segments/{$segId}/contacts")->assertOk()->json('meta.total'),
        );
    }

    public function test_empty_rules_population_matches_customers_tab(): void
    {
        // A buyer whose only order is pending_payment IS a CRM customer (the
        // Customers tab applies no status filter) — a rules-empty dynamic
        // segment must agree with that, not silently drop them.
        $company = Company::query()->create(['name' => 'Pop Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);
        $pending = User::factory()->create();
        $order = $this->order($pending, $company, 70);
        $order->forceFill(['status' => 'pending_payment'])->save();

        Sanctum::actingAs($owner->fresh());

        $seg = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Everyone', 'type' => 'dynamic',
        ])->assertCreated()->json();
        $this->assertSame(1, $seg['data']['contacts_count']);

        // But a purchase-quality rule (min_bookings) counts only the
        // company's configured sale statuses — pending_payment is not one of
        // the defaults, so this buyer drops out.
        $strict = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Real buyers', 'type' => 'dynamic', 'rules' => ['min_bookings' => 1],
        ])->assertCreated()->json();
        $this->assertSame(0, $strict['data']['contacts_count']);
    }

    public function test_members_cannot_be_added_to_dynamic_segment(): void
    {
        $company = Company::query()->create(['name' => 'Dyn Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);
        $customer = User::factory()->create();

        Sanctum::actingAs($owner->fresh());

        $seg = $this->postJson('/api/platform-admin/crm/segments', [
            'name' => 'Dyn', 'type' => 'dynamic',
        ])->assertCreated()->json();

        $this->postJson('/api/platform-admin/crm/segments/'.$seg['data']['id'].'/members', ['user_id' => $customer->id])
            ->assertStatus(422);
    }

    public function test_plain_employee_cannot_write_segments(): void
    {
        $company = Company::query()->create(['name' => 'RO Co', 'type' => 'operator']);
        $viewer = User::factory()->create();
        $viewer->companies()->attach($company->id, ['role_id' => $this->roleId('company_viewer')]);
        $segment = CrmSegment::query()->create(['name' => 'S', 'type' => 'static', 'company_id' => $company->id]);

        Sanctum::actingAs($viewer->fresh());

        // Reads are fine (view-only page)…
        $this->getJson('/api/platform-admin/crm/segments')->assertOk();
        // …writes are refused.
        $this->postJson('/api/platform-admin/crm/segments', ['name' => 'New', 'type' => 'static'])
            ->assertForbidden();
        $this->patchJson('/api/platform-admin/crm/segments/'.$segment->id, ['name' => 'Renamed'])
            ->assertForbidden();
        $this->deleteJson('/api/platform-admin/crm/segments/'.$segment->id)->assertForbidden();
    }

    public function test_cross_company_segment_access_is_forbidden(): void
    {
        $mine = Company::query()->create(['name' => 'M Co', 'type' => 'operator']);
        $other = Company::query()->create(['name' => 'O Co', 'type' => 'operator']);
        $owner = $this->makeOwner($mine);
        $foreign = CrmSegment::query()->create(['name' => 'F', 'type' => 'static', 'company_id' => $other->id]);

        Sanctum::actingAs($owner->fresh());

        // Not in the list…
        $res = $this->getJson('/api/platform-admin/crm/segments')->assertOk()->json();
        $this->assertCount(0, $res['data']);
        // …and not writable or readable in detail.
        $this->patchJson('/api/platform-admin/crm/segments/'.$foreign->id, ['name' => 'Hack'])
            ->assertForbidden();
        $this->getJson('/api/platform-admin/crm/segments/'.$foreign->id.'/contacts')
            ->assertForbidden();
    }

    public function test_super_admin_sees_all_segments(): void
    {
        $a = Company::query()->create(['name' => 'SA', 'type' => 'operator']);
        $b = Company::query()->create(['name' => 'SB', 'type' => 'operator']);
        CrmSegment::query()->create(['name' => 'Seg A', 'type' => 'static', 'company_id' => $a->id]);
        CrmSegment::query()->create(['name' => 'Seg B', 'type' => 'static', 'company_id' => $b->id]);

        $super = User::factory()->create();
        $super->companies()->attach($a->id, ['role_id' => $this->roleId('super_admin')]);

        Sanctum::actingAs($super->fresh());

        $res = $this->getJson('/api/platform-admin/crm/segments')->assertOk()->json();
        $this->assertCount(2, $res['data']);
    }
}
