<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/platform-admin/crm/customers/stats — full-dataset stat cards over
 * the SAME scoped customer set as crm/customers (the caller's own buyers).
 * Operator sees only their buyers' counts; super sees every buyer.
 */
class CrmCustomersStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    /**
     * @param  array{status?: string, created_at?: Carbon}  $attrs
     */
    private function buyerWithOrder(int $companyId, string $email, array $attrs = []): User
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $buyer = User::factory()->create(array_merge(['email' => $email], $attrs));
        // Pin created_at via raw update — factory()->create() re-stamps
        // timestamps on insert, which would break new_this_month assertions.
        if ($createdAt !== null) {
            DB::table('users')->where('id', $buyer->id)->update(['created_at' => $createdAt]);
            $buyer = $buyer->fresh();
        }

        Order::query()->create([
            'order_number' => 'CCS-'.str()->uuid(), 'user_id' => $buyer->id,
            'company_id' => $companyId, 'status' => 'paid', 'currency' => 'USD', 'total' => 80,
        ]);

        return $buyer;
    }

    public function test_operator_sees_only_their_own_scoped_counts(): void
    {
        $companyA = Company::query()->create(['name' => 'Stats A', 'type' => 'operator']);
        $companyB = Company::query()->create(['name' => 'Stats B', 'type' => 'operator']);

        // Two buyers for A: one active+this-month, one inactive+old.
        $this->buyerWithOrder($companyA->id, 'a-active@example.test', [
            'status' => 'active', 'created_at' => now(),
        ]);
        $this->buyerWithOrder($companyA->id, 'a-old@example.test', [
            'status' => 'pending', 'created_at' => now()->subMonths(2)->startOfMonth(),
        ]);
        // Buyer for B must NOT leak into A's counts.
        $this->buyerWithOrder($companyB->id, 'b-active@example.test', [
            'status' => 'active', 'created_at' => now(),
        ]);

        $operator = User::factory()->create();
        $operator->companies()->attach($companyA->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);

        Sanctum::actingAs($operator->fresh());
        $res = $this->getJson('/api/platform-admin/crm/customers/stats')->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertSame(2, $res->json('data.with_bookings'));   // both A buyers
        $this->assertSame(1, $res->json('data.active'));          // only a-active
        $this->assertSame(1, $res->json('data.new_this_month'));  // only a-active
    }

    public function test_super_sees_all_buyers(): void
    {
        $companyA = Company::query()->create(['name' => 'SStats A', 'type' => 'operator']);
        $companyB = Company::query()->create(['name' => 'SStats B', 'type' => 'operator']);
        $this->buyerWithOrder($companyA->id, 'sa@example.test', ['status' => 'active', 'created_at' => now()]);
        $this->buyerWithOrder($companyB->id, 'sb@example.test', ['status' => 'active', 'created_at' => now()->subMonths(3)]);

        $super = User::factory()->create();
        $super->companies()->attach($companyA->id, ['role_id' => Role::query()->where('name', 'super_admin')->value('id')]);

        Sanctum::actingAs($super->fresh());
        $res = $this->getJson('/api/platform-admin/crm/customers/stats')->assertOk();

        $this->assertSame(2, $res->json('data.with_bookings'));   // both companies' buyers
        $this->assertSame(2, $res->json('data.active'));
        $this->assertSame(1, $res->json('data.new_this_month'));  // only the current-month one
    }
}
