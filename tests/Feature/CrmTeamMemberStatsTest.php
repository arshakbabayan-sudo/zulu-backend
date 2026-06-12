<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmDeal;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap §4 (2026-06-12) — My-team employee detail.
 *
 * GET crm/team/{user}/stats — monthly won-deal revenue series + by-service /
 * top-destination breakdowns of the employee's direct bookings.
 *
 * Also guards the teamCompanyId FIX: GET crm/team?company_id=<foreign> used to
 * hand ANY operator another company's member emails, roles and computed pay —
 * tenants are now pinned to their own companies.
 */
class CrmTeamMemberStatsTest extends TestCase
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

    private function company(string $name): Company
    {
        return Company::query()->create(['name' => $name, 'type' => 'operator']);
    }

    private function member(Company $company, string $role = 'company_admin'): User
    {
        $u = User::factory()->create();
        $u->companies()->attach($company->id, ['role_id' => $this->roleId($role)]);

        return $u;
    }

    private function wonDeal(Company $company, User $owner, float $amount, string $currency = 'USD'): CrmDeal
    {
        return CrmDeal::query()->create([
            'title' => 'Won '.str()->uuid(),
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
            'stage' => 'won',
            'value_amount' => $amount,
            'currency' => $currency,
        ]);
    }

    private function directOrderWithHotelItem(Company $company, User $seller): void
    {
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'TM Hotel '.str()->uuid(),
            'price' => 100,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        $hotel = Hotel::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'location_id' => $this->locationIds()['yerevan_city'],
            'hotel_name' => 'TM Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'meal_type' => 'bed_and_breakfast',
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);
        $buyer = User::factory()->create();
        $order = Order::query()->create([
            'company_id' => $company->id,
            'user_id' => $buyer->id,
            'order_number' => 'ORD-'.str()->upper(str()->random(10)),
            'buyer_type' => 'client',
            'status' => 'confirmed',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);
        // sold_by_user_id is intentionally NOT mass-assignable (the booking
        // flows stamp it explicitly) — mirror that here.
        $order->forceFill(['sold_by_user_id' => $seller->id])->save();
        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => (int) $hotel->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
            'currency' => 'USD',
            'status' => 'pending',
        ]);
    }

    public function test_member_stats_returns_monthly_series_and_breakdowns(): void
    {
        $company = $this->company('Stats Co');
        $owner = $this->member($company);
        $emp = $this->member($company, 'company_viewer');

        $this->wonDeal($company, $emp, 300.00);
        $this->wonDeal($company, $emp, 200.00);
        // Other company's deal by the same user must NOT count.
        $other = $this->company('Other Co');
        $this->wonDeal($other, $emp, 999.00);

        $this->directOrderWithHotelItem($company, $emp);

        Sanctum::actingAs($owner->fresh());

        $data = $this->getJson("/api/platform-admin/crm/team/{$emp->id}/stats")
            ->assertOk()
            ->json('data');

        $this->assertCount(6, $data['monthly']);
        $current = end($data['monthly']);
        $this->assertSame(now()->format('Y-m'), $current['month']);
        $this->assertSame(2, $current['won']);
        $this->assertEqualsWithDelta(500.0, $current['revenue'][0]['total'], 0.01);

        $svc = collect($data['services'])->keyBy('type');
        $this->assertSame(1, $svc['hotel']['bookings']);
        $dest = collect($data['destinations'])->keyBy('name');
        $this->assertSame(1, $dest['Yerevan']['bookings']);
    }

    public function test_stats_for_non_member_user_is_404(): void
    {
        $company = $this->company('A Co');
        $owner = $this->member($company);
        $stranger = User::factory()->create();

        Sanctum::actingAs($owner->fresh());

        $this->getJson("/api/platform-admin/crm/team/{$stranger->id}/stats")
            ->assertNotFound();
    }

    public function test_team_and_stats_ignore_foreign_company_id_for_tenants(): void
    {
        $mine = $this->company('Mine');
        $other = $this->company('Other');
        $me = $this->member($mine);
        $foreignEmp = $this->member($other, 'company_viewer');

        Sanctum::actingAs($me->fresh());

        // The old behaviour returned Other's member list (emails, roles, pay).
        $team = $this->getJson('/api/platform-admin/crm/team?company_id='.$other->id)
            ->assertOk()
            ->json();
        $this->assertSame($mine->id, $team['meta']['company_id']);
        $emails = collect($team['data'])->pluck('user.email');
        $this->assertFalse($emails->contains($foreignEmp->email));

        // Same pin on the stats endpoint: the foreign member reads as 404
        // (not a member of MY company), never their numbers.
        $this->getJson("/api/platform-admin/crm/team/{$foreignEmp->id}/stats?company_id={$other->id}")
            ->assertNotFound();
    }

    public function test_team_rows_carry_profile_fields(): void
    {
        $company = $this->company('Prof Co');
        $owner = $this->member($company);

        Sanctum::actingAs($owner->fresh());

        $row = collect($this->getJson('/api/platform-admin/crm/team')->assertOk()->json('data'))
            ->firstWhere('user.id', $owner->id);

        $this->assertArrayHasKey('phone', $row['user']);
        $this->assertArrayHasKey('joined_at', $row['user']);
        $this->assertArrayHasKey('last_login_at', $row['user']);
        $this->assertNotNull($row['user']['joined_at']);
    }
}
