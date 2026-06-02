<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformStaffScope;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RBAC blueprint Phase 5 — aggregate stat cards scoped to visibleCompanyIds.
 *
 * End-to-end through the real gated endpoint:
 *  - super-admin sees platform-wide totals
 *  - a platform_admin staffer assigned only company A sees ONLY company A's
 *    totals (not company B's), and the scope-suffixed cache keeps the two
 *    callers' numbers separate.
 */
class BookingsStatsScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach (['super_admin', 'platform_admin'] as $name) {
            Role::query()->firstOrCreate(['name' => $name]);
        }
    }

    private function paidBooking(int $companyId, float $total): Order
    {
        return Order::query()->create([
            'order_number' => 'ST-'.str()->uuid(),
            'company_id' => $companyId,
            'status' => 'paid',
            'currency' => 'USD',
            'total' => $total,
        ]);
    }

    private function staffOn(string $role): User
    {
        $user = User::factory()->create();
        $home = Company::query()->create(['name' => 'Home '.uniqid(), 'type' => 'operator', 'status' => 'active']);
        $user->companies()->attach($home->id, ['role_id' => Role::query()->where('name', $role)->value('id')]);

        return $user->fresh();
    }

    public function test_super_sees_all_companies_but_staff_sees_only_assigned(): void
    {
        $companyA = Company::query()->create(['name' => 'Stats A', 'type' => 'operator', 'status' => 'active']);
        $companyB = Company::query()->create(['name' => 'Stats B', 'type' => 'operator', 'status' => 'active']);

        $this->paidBooking($companyA->id, 100);
        $this->paidBooking($companyB->id, 200);

        $super = $this->staffOn('super_admin');
        $staff = $this->staffOn('platform_admin');
        // Assign the staffer ONLY company A.
        PlatformStaffScope::query()->create(['user_id' => $staff->id, 'company_id' => $companyA->id]);

        // Super: platform-wide revenue includes both (>= 300).
        Sanctum::actingAs($super);
        $superRes = $this->getJson('/api/platform-admin/bookings/stats?range=30d')->assertOk();
        $this->assertEqualsWithDelta(300.0, (float) $superRes->json('data.revenue_amount'), 0.01);

        // Staff: only company A's revenue (100), NOT company B's.
        Sanctum::actingAs($staff);
        $staffRes = $this->getJson('/api/platform-admin/bookings/stats?range=30d')->assertOk();
        $this->assertEqualsWithDelta(100.0, (float) $staffRes->json('data.revenue_amount'), 0.01);
    }

    public function test_staff_without_assignment_sees_zero(): void
    {
        $company = Company::query()->create(['name' => 'Stats C', 'type' => 'operator', 'status' => 'active']);
        $this->paidBooking($company->id, 500);

        $staff = $this->staffOn('platform_admin'); // no platform_staff_scope rows

        Sanctum::actingAs($staff);
        $res = $this->getJson('/api/platform-admin/bookings/stats?range=30d')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $res->json('data.revenue_amount'), 0.01);
    }
}
