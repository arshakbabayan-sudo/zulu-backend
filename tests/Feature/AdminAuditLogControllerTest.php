<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Audit\AuditService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_403_for_non_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/platform-admin/audit-logs')->assertStatus(403);
    }

    public function test_index_returns_paginated_logs_with_filters(): void
    {
        $admin = $this->createPlatformAdmin();
        $service = app(AuditService::class);

        $service->log(['category' => 'contract', 'actor' => $admin, 'subject_type' => 'Contract', 'subject_id' => 'c1', 'action' => 'sent']);
        $service->log(['category' => 'data_change', 'actor' => $admin, 'subject_type' => 'Voucher', 'subject_id' => 'v1', 'action' => 'issued']);
        $service->log(['category' => 'financial', 'actor' => $admin, 'subject_type' => 'Payment', 'subject_id' => 'p1', 'action' => 'failed']);

        Sanctum::actingAs($admin);
        $all = $this->getJson('/api/platform-admin/audit-logs');
        $all->assertOk();
        $this->assertSame(3, $all->json('meta.total'));

        $filtered = $this->getJson('/api/platform-admin/audit-logs?category=contract');
        $this->assertSame(1, $filtered->json('meta.total'));

        $bySubject = $this->getJson('/api/platform-admin/audit-logs?subject_type=Voucher&subject_id=v1');
        $this->assertSame(1, $bySubject->json('meta.total'));
    }

    public function test_show_returns_single_log_or_404(): void
    {
        $admin = $this->createPlatformAdmin();
        $log = app(AuditService::class)->log([
            'category' => 'system',
            'subject_type' => 'X',
            'subject_id' => 'y',
            'action' => 'a',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/platform-admin/audit-logs/'.$log->id)->assertOk();
        $this->getJson('/api/platform-admin/audit-logs/00000000-0000-0000-0000-000000000000')->assertStatus(404);
    }

    public function test_verify_integrity_endpoint_returns_clean_for_intact_chain(): void
    {
        $admin = $this->createPlatformAdmin();
        $service = app(AuditService::class);
        $service->log(['category' => 'system', 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'a']);
        $service->log(['category' => 'system', 'subject_type' => 'X', 'subject_id' => '2', 'action' => 'b']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/audit-logs/verify-integrity');

        $response->assertOk();
        $response->assertJsonPath('data.is_intact', true);
        $this->assertSame([], $response->json('data.corrupted_log_ids'));
    }

    public function test_verify_integrity_endpoint_detects_tampering(): void
    {
        $admin = $this->createPlatformAdmin();
        $service = app(AuditService::class);
        $service->log(['category' => 'system', 'subject_type' => 'X', 'subject_id' => '1', 'action' => 'a']);
        $tampered = $service->log(['category' => 'system', 'subject_type' => 'X', 'subject_id' => '2', 'action' => 'b']);
        $service->log(['category' => 'system', 'subject_type' => 'X', 'subject_id' => '3', 'action' => 'c']);

        DB::table('audit_logs')->where('id', $tampered->id)->update(['action' => 'mutated']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/audit-logs/verify-integrity');

        $response->assertOk();
        $response->assertJsonPath('data.is_intact', false);
        $this->assertContains($tampered->id, $response->json('data.corrupted_log_ids'));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}
