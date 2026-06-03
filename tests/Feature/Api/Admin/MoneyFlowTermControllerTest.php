<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\MoneyFlowTerm;
use App\Models\PricingAuditLog;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MoneyFlowTermControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    private User $superAdmin;

    private User $companyAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'TestCo '.uniqid(), 'type' => 'operator',
            'created_at' => now(), 'updated_at' => now(),
        ]);

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

    public function test_non_super_admin_blocked(): void
    {
        Sanctum::actingAs($this->companyAdmin);
        $this->getJson('/api/platform-admin/money-flow-terms')->assertStatus(403);
    }

    public function test_create_global_zulu_collects_term(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/platform-admin/money-flow-terms', [
            'scope_type' => 'global',
            'collection_model' => 'zulu_collects',
            'remittance_days' => 30,
            'is_active' => true,
            'reason' => 'extending remit window to 30 days',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.collection_model', 'zulu_collects');
        $response->assertJsonPath('data.remittance_days', 30);

        $log = PricingAuditLog::query()->latest('id')->first();
        $this->assertSame('money_flow_term', $log->entity_type);
        $this->assertSame('created', $log->action);
        $this->assertSame('extending remit window to 30 days', $log->reason);
    }

    public function test_zulu_collects_requires_remittance_days(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/platform-admin/money-flow-terms', [
            'scope_type' => 'global',
            'collection_model' => 'zulu_collects',
            // remittance_days missing → 422
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['remittance_days']);
    }

    public function test_operator_collects_requires_invoicing_period(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/platform-admin/money-flow-terms', [
            'scope_type' => 'operator',
            'operator_id' => $this->companyId,
            'collection_model' => 'operator_collects',
            // invoicing_period missing → 422
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoicing_period']);
    }

    public function test_partnership_requires_operator_and_agent(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/platform-admin/money-flow-terms', [
            'scope_type' => 'partnership',
            'collection_model' => 'zulu_collects',
            'remittance_days' => 15,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['operator_id', 'agent_id']);
    }

    public function test_update_writes_audit_log(): void
    {
        $term = MoneyFlowTerm::create([
            'scope_type' => 'global',
            'collection_model' => 'zulu_collects',
            'remittance_days' => 15,
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->superAdmin);

        $this->patchJson("/api/platform-admin/money-flow-terms/{$term->id}", [
            'remittance_days' => 7,
            'reason' => 'tightening to T+7',
        ])->assertOk();

        $log = PricingAuditLog::query()->where('action', 'updated')->latest('id')->first();
        $this->assertSame('money_flow_term', $log->entity_type);
        $this->assertSame('tightening to T+7', $log->reason);
        $this->assertEquals(15, (int) $log->old_values['remittance_days']);
    }

    public function test_destroy_writes_audit_and_removes_row(): void
    {
        $term = MoneyFlowTerm::create([
            'scope_type' => 'global',
            'collection_model' => 'zulu_collects',
            'remittance_days' => 15,
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->superAdmin);

        $this->deleteJson("/api/platform-admin/money-flow-terms/{$term->id}", [
            'reason' => 'replaced with operator-specific term',
        ])->assertOk();

        $this->assertSame(0, MoneyFlowTerm::query()->whereKey($term->id)->count());
        $this->assertSame(1, PricingAuditLog::query()->where('action', 'deleted')->count());
    }
}
