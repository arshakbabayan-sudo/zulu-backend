<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap §6 — GET /platform-admin/finance-summary/pdf (Finance summary PDF
 * export). Platform-admin only; reuses the summaryV2 + revenueByService
 * aggregates and streams a dompdf download via FinanceSummaryPdfService.
 */
class FinanceSummaryPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_403_for_non_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/platform-admin/finance-summary/pdf?range=30d')->assertStatus(403);
    }

    public function test_platform_admin_gets_pdf_download(): void
    {
        Sanctum::actingAs($this->createPlatformAdmin());

        $response = $this->get('/api/platform-admin/finance-summary/pdf?range=30d');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('finance-summary-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}
