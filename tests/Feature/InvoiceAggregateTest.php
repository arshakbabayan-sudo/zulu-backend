<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.3 feature tests — per-X invoicing aggregate endpoint.
 *
 * Route: GET /api/invoices/aggregate
 *
 * Slicing dimensions:
 *   - status   → issue/paid/cancelled/pending
 *   - currency → ISO code
 *   - month    → YYYY-MM (Postgres TO_CHAR — skipped on SQLite)
 *   - operator → seller company on linked order
 *
 * Company scoping reuses the regular invoices.view rule via
 * AdminAccessService::companyIdsForCommerceList — null = full visibility
 * (super admin / platform admin).
 */
class InvoiceAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/invoices/aggregate')->assertStatus(401);
    }

    public function test_default_group_by_status_returns_status_buckets(): void
    {
        $admin = $this->makePlatformAdmin();
        $company = $this->makeCompany();
        $order = $this->makeOrder($company);

        // Two issued, one paid, one pending — group by status should return three buckets.
        $this->makeInvoice($order, ['status' => 'issued', 'total_amount' => 100, 'currency' => 'USD']);
        $this->makeInvoice($order, ['status' => 'issued', 'total_amount' => 50, 'currency' => 'USD']);
        $this->makeInvoice($order, ['status' => 'paid', 'total_amount' => 200, 'currency' => 'USD']);
        $this->makeInvoice($order, ['status' => 'pending', 'total_amount' => 75, 'currency' => 'USD']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/invoices/aggregate');
        $response->assertOk();
        $response->assertJsonPath('data.group_by', 'status');

        $buckets = collect($response->json('data.buckets'));
        $byStatus = $buckets->keyBy('bucket');

        $this->assertEquals(2, $byStatus['issued']['invoice_count']);
        $this->assertEquals(150.0, $byStatus['issued']['total_sum']);
        $this->assertEquals(1, $byStatus['paid']['invoice_count']);
        $this->assertEquals(200.0, $byStatus['paid']['total_sum']);
        $this->assertEquals(1, $byStatus['pending']['invoice_count']);
    }

    public function test_group_by_currency_splits_invoices_per_currency(): void
    {
        $admin = $this->makePlatformAdmin();
        $company = $this->makeCompany();
        $order = $this->makeOrder($company);

        $this->makeInvoice($order, ['status' => 'paid', 'total_amount' => 100, 'currency' => 'USD']);
        $this->makeInvoice($order, ['status' => 'paid', 'total_amount' => 150, 'currency' => 'USD']);
        $this->makeInvoice($order, ['status' => 'paid', 'total_amount' => 5000, 'currency' => 'AMD']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/invoices/aggregate?group_by=currency');
        $response->assertOk();
        $response->assertJsonPath('data.group_by', 'currency');

        $buckets = collect($response->json('data.buckets'));
        $byCurrency = $buckets->keyBy('bucket');

        $this->assertEquals(2, $byCurrency['USD']['invoice_count']);
        $this->assertEquals(250.0, $byCurrency['USD']['total_sum']);
        $this->assertEquals(1, $byCurrency['AMD']['invoice_count']);
        $this->assertEquals(5000.0, $byCurrency['AMD']['total_sum']);
    }

    public function test_staff_without_invoices_view_permission_sees_empty_aggregate(): void
    {
        // RBAC permissions aren't seeded in :memory: SQLite, so a fresh
        // company_admin role has no `invoices.view` permission attached.
        // companyIdsForCommerceList returns an empty array → the query
        // filters whereIn('company_id', []), yielding zero buckets.
        // This locks down the negative case: missing permission ⇒ no data.
        $companyA = $this->makeCompany();
        $orderA = $this->makeOrder($companyA);
        $this->makeInvoice($orderA, ['status' => 'paid', 'total_amount' => 100, 'currency' => 'USD']);

        $userA = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $userA->companies()->attach($companyA->id, ['role_id' => $role->id]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/invoices/aggregate?group_by=status');
        $response->assertOk();

        $buckets = $response->json('data.buckets');
        $this->assertSame([], $buckets);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.3 Test',
            'email' => 'p73-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.3 '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function makePlatformAdmin(): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'platform_admin']);
        $platform = $this->makeCompany();
        $user->companies()->attach($platform->id, ['role_id' => $role->id]);

        return $user;
    }

    private function makeOrder(Company $company): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'order_number' => 'ORD-'.str()->upper(str()->random(10)),
            'buyer_type' => 'client',
            'status' => 'confirmed',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'metadata' => ['legacy_origin' => 'booking'],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeInvoice(Order $order, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'order_id' => $order->id,
            'total_amount' => 100,
            'currency' => 'USD',
            'status' => 'issued',
            'issuing_date' => now(),
        ], $overrides));
    }
}
