<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\User;
use App\Models\Visa;
use App\Models\VisaApplication;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the Sprint 63 visa application admin endpoints.
 */
class AdminVisaApplicationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_platform_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/platform-admin/visa-applications')->assertStatus(403);
    }

    public function test_index_lists_paginated_with_status_filter(): void
    {
        $admin = $this->createPlatformAdmin();
        $applicant = User::factory()->create();
        $visa = $this->makeVisa('Schengen', 'France');

        VisaApplication::query()->create([
            'user_id' => $applicant->id, 'visa_id' => $visa->id,
            'status' => 'pending', 'passport_number' => 'AB123',
        ]);
        VisaApplication::query()->create([
            'user_id' => $applicant->id, 'visa_id' => $visa->id,
            'status' => 'approved', 'passport_number' => 'AB124',
        ]);

        Sanctum::actingAs($admin);
        $all = $this->getJson('/api/platform-admin/visa-applications');
        $all->assertOk();
        $this->assertSame(2, $all->json('meta.total'));

        $pending = $this->getJson('/api/platform-admin/visa-applications?status=pending');
        $this->assertSame(1, $pending->json('meta.total'));
        $this->assertSame('pending', $pending->json('data.0.status'));
    }

    public function test_decide_updates_status_and_creates_notification(): void
    {
        $admin = $this->createPlatformAdmin();
        $applicant = User::factory()->create();
        $visa = $this->makeVisa('US tourist', 'USA');
        $application = VisaApplication::query()->create([
            'user_id' => $applicant->id, 'visa_id' => $visa->id,
            'status' => 'pending', 'passport_number' => 'XY789',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->postJson(
            "/api/platform-admin/visa-applications/{$application->id}/decide",
            ['status' => 'approved', 'note' => 'All documents verified']
        );
        $response->assertOk();
        $this->assertSame('approved', $response->json('data.status'));

        $application->refresh();
        $this->assertSame('approved', $application->status);
        $this->assertSame('All documents verified', $application->admin_notes);

        // Notification should have been created.
        $notification = Notification::query()
            ->where('user_id', $applicant->id)
            ->where('event_type', 'visa.application.approved')
            ->first();
        $this->assertNotNull($notification);
        $this->assertSame('unread', $notification->status);
    }

    public function test_stats_returns_counters_by_status(): void
    {
        $admin = $this->createPlatformAdmin();
        $applicant = User::factory()->create();
        $visa = $this->makeVisa('X', 'Y');

        foreach (['pending', 'pending', 'approved', 'rejected'] as $i => $status) {
            VisaApplication::query()->create([
                'user_id' => $applicant->id, 'visa_id' => $visa->id,
                'status' => $status, 'passport_number' => 'P'.$i,
            ]);
        }

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/visa-applications/stats');
        $response->assertOk();
        $this->assertSame(4, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.pending'));
        $this->assertSame(1, $response->json('data.approved'));
        $this->assertSame(1, $response->json('data.rejected'));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    private function makeVisa(string $title, string $country): Visa
    {
        $company = Company::query()->create([
            'name' => 'Visa Co '.uniqid(),
            'type' => 'tour_operator',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'visa',
            'title' => $title,
            'price' => 50,
            'currency' => 'USD',
            'status' => 'published',
        ]);

        return Visa::query()->create([
            'offer_id' => $offer->id,
            'name' => $title,
            'visa_type' => 'tourist',
            'price' => 50,
        ]);
    }
}
