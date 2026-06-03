<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\PlatformAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 Layer-B (within-company row scope) — proves PlatformAdminService
 * filters bookings by orders.sold_by_user_id when the controller passes a
 * non-null scope_attribution_user_id (a plain employee), and shows the whole
 * company when it's null (owner / *.view_all holder).
 *
 * Tested at the SERVICE level on purpose: the HTTP route is gated for
 * operators/employees until Phase 6 (EnsurePlatformAdmin still uses
 * isPlatformAdmin), so a feature test through the endpoint can't reach an
 * employee caller yet. The controller→service contract (scope_company_ids +
 * scope_attribution_user_id) is what carries the row scope.
 */
class PlatformAdminBookingsRowScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(int $companyId, int $buyerUserId, ?int $soldBy): Order
    {
        $order = Order::query()->create([
            'order_number' => 'BR-'.str()->uuid(),
            'user_id' => $buyerUserId,
            'company_id' => $companyId,
            'status' => 'paid',
            'currency' => 'USD',
            'total' => 100,
        ]);
        // sold_by_user_id is not in $fillable (set by the booking flow, not
        // mass-assigned), so stamp it explicitly.
        $order->forceFill(['sold_by_user_id' => $soldBy])->save();

        return $order;
    }

    public function test_employee_sees_only_their_own_sold_bookings(): void
    {
        $company = Company::query()->create(['name' => 'RowScope Co', 'type' => 'operator']);
        $buyer = User::factory()->create();
        $employee = User::factory()->create();
        $colleague = User::factory()->create();

        $mine = $this->makeOrder($company->id, $buyer->id, $employee->id);
        $theirs = $this->makeOrder($company->id, $buyer->id, $colleague->id);
        $unattributed = $this->makeOrder($company->id, $buyer->id, null);

        $service = app(PlatformAdminService::class);

        // Plain employee: company scope + own-rows attribution.
        $page = $service->listAllBookings([
            'scope_company_ids' => [$company->id],
            'scope_attribution_user_id' => $employee->id,
        ], 50);

        $ids = collect($page->items())->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($unattributed->id, $ids);
        $this->assertSame(1, $page->total());
    }

    public function test_owner_view_sees_whole_company(): void
    {
        $company = Company::query()->create(['name' => 'RowScope Co2', 'type' => 'operator']);
        $buyer = User::factory()->create();
        $employee = User::factory()->create();

        $this->makeOrder($company->id, $buyer->id, $employee->id);
        $this->makeOrder($company->id, $buyer->id, null);

        $service = app(PlatformAdminService::class);

        // Owner / view_all: null attribution → no row filter → all company rows.
        $page = $service->listAllBookings([
            'scope_company_ids' => [$company->id],
            'scope_attribution_user_id' => null,
        ], 50);

        $this->assertSame(2, $page->total());
    }

    public function test_other_company_rows_never_leak_through_attribution(): void
    {
        $companyA = Company::query()->create(['name' => 'RS A', 'type' => 'operator']);
        $companyB = Company::query()->create(['name' => 'RS B', 'type' => 'operator']);
        $buyer = User::factory()->create();
        $employee = User::factory()->create();

        // Same employee id "sold" something in company B (shouldn't happen, but
        // proves the company layer fences the attribution layer).
        $this->makeOrder($companyB->id, $buyer->id, $employee->id);
        $mineInA = $this->makeOrder($companyA->id, $buyer->id, $employee->id);

        $service = app(PlatformAdminService::class);
        $page = $service->listAllBookings([
            'scope_company_ids' => [$companyA->id],
            'scope_attribution_user_id' => $employee->id,
        ], 50);

        $ids = collect($page->items())->pluck('id')->all();
        $this->assertSame([$mineInA->id], $ids);
    }
}
