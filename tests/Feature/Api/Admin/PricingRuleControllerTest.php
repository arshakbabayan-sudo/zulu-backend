<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\PricingAuditLog;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 / Step D.1 — pricing-rules CRUD endpoint tests.
 *
 * Verifies:
 *  - super-admin auth required (403 for company_admin)
 *  - create/update/delete go through audit log
 *  - test endpoint returns resolver output
 *  - scope-shape validation (partnership needs both operator + agent)
 */
class PricingRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    private int $offerId;

    private User $superAdmin;

    private User $companyAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'TestCo '.uniqid(), 'type' => 'operator',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->offerId = DB::table('offers')->insertGetId([
            'company_id' => $this->companyId, 'type' => 'hotel',
            'title' => 'Test', 'price' => 100, 'currency' => 'EUR',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Roles per B.0 migration
        foreach (['super_admin' => 'platform', 'company_admin' => 'company'] as $name => $scope) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                ['name' => $name, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $superRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $companyRoleId = DB::table('roles')->where('name', 'company_admin')->value('id');

        $this->superAdmin = User::factory()->create();
        UserCompany::create([
            'user_id' => $this->superAdmin->id,
            'company_id' => $this->companyId,
            'role_id' => $superRoleId,
        ]);
        $this->superAdmin->refresh();

        $this->companyAdmin = User::factory()->create();
        UserCompany::create([
            'user_id' => $this->companyAdmin->id,
            'company_id' => $this->companyId,
            'role_id' => $companyRoleId,
        ]);
        $this->companyAdmin->refresh();
    }

    public function test_non_super_admin_gets_403_on_index(): void
    {
        Sanctum::actingAs($this->companyAdmin);
        $this->getJson('/api/platform-admin/pricing-rules')->assertStatus(403);
    }

    public function test_super_admin_can_list_rules(): void
    {
        PricingRule::create([
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 15, 'currency' => 'EUR',
            'effective_from' => now()->subDay(), 'priority' => 10, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->superAdmin);
        $response = $this->getJson('/api/platform-admin/pricing-rules');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_create_a_global_rule_writes_audit_log(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/platform-admin/pricing-rules', [
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 20,
            'currency' => 'USD',
            'effective_from' => now()->toIso8601String(),
            'priority' => 50,
            'is_active' => true,
            'reason' => 'launching USD market',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.scope_type', 'global');
        $this->assertEquals(20.0, $response->json('data.markup_value'));

        $this->assertSame(1, PricingRule::count());
        $log = PricingAuditLog::query()->latest('id')->first();
        $this->assertSame('pricing_rule', $log->entity_type);
        $this->assertSame('created', $log->action);
        $this->assertSame($this->superAdmin->id, $log->changed_by);
        $this->assertSame('launching USD market', $log->reason);
    }

    public function test_partnership_scope_requires_operator_and_agent_ids(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/platform-admin/pricing-rules', [
            'scope_type' => PricingRule::SCOPE_PARTNERSHIP,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 10,
            'currency' => 'EUR',
            'effective_from' => now()->toIso8601String(),
            // operator_id + agent_id missing → 422
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['operator_id', 'agent_id']);
    }

    public function test_update_writes_audit_log_with_old_and_new_values(): void
    {
        $rule = PricingRule::create([
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 15, 'currency' => 'EUR',
            'effective_from' => now()->subDay(), 'priority' => 10, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->superAdmin);

        $response = $this->patchJson("/api/platform-admin/pricing-rules/{$rule->id}", [
            'markup_value' => 25,
            'reason' => 'EOY promo lift',
        ]);

        $response->assertOk();
        $this->assertEquals(25.0, $response->json('data.markup_value'));

        $log = PricingAuditLog::query()->where('action', 'updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('EOY promo lift', $log->reason);
        $this->assertEquals(15, (float) $log->old_values['markup_value']);
    }

    public function test_destroy_soft_deletes_and_writes_audit(): void
    {
        $rule = PricingRule::create([
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 15, 'currency' => 'EUR',
            'effective_from' => now()->subDay(), 'priority' => 10, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->superAdmin);
        $this->deleteJson("/api/platform-admin/pricing-rules/{$rule->id}", ['reason' => 'sunset old rule'])
            ->assertOk();

        $this->assertSoftDeleted('pricing_rules', ['id' => $rule->id]);
        $this->assertSame(1, PricingAuditLog::query()->where('action', 'deleted')->count());
    }

    public function test_test_endpoint_returns_resolver_output(): void
    {
        PricingRule::create([
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 20, 'currency' => 'EUR',
            'effective_from' => now()->subDay(), 'priority' => 50, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/platform-admin/pricing-rules/test', [
            'offer_id' => $this->offerId,
            'quantity' => 2,
        ]);

        $response->assertOk();
        // 100 * 1.20 = 120 supplier-net + markup → customer_price
        $this->assertEquals(100.0, $response->json('data.supplier_net'));
        $this->assertEquals(120.0, $response->json('data.customer_price'));
        $this->assertEquals(240.0, $response->json('data.line_total'));
        $response->assertJsonPath('data.currency', 'EUR');
    }
}
