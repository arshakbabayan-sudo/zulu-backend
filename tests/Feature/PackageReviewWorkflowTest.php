<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §10 — package multi-level approval (Arshak 2026-06-16: only a company's FIRST
 * package needs ZULU approval; afterwards it self-publishes freely).
 */
class PackageReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
    }

    private function makeCompany(bool $trusted = false): Company
    {
        return Company::create([
            'name' => 'Partner '.uniqid(),
            'type' => 'operator',
            'slug' => 'partner-'.uniqid(),
            'packages_trusted_at' => $trusted ? now() : null,
        ]);
    }

    private function makeOperatorFor(Company $company): User
    {
        $user = User::factory()->create();
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => Role::query()->where('name', 'company_admin')->firstOrFail()->id,
        ]);

        return $user;
    }

    private function makeDraftPackage(Company $company, string $status = 'draft'): Package
    {
        $offer = Offer::create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Trip',
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        return Package::create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Trip',
            'package_subtitle' => 'Sub',
            'destination_country' => 'Armenia',
            'destination_city' => 'Yerevan',
            'duration_days' => 5,
            'base_price' => 500,
            'display_price_mode' => 'total',
            'currency' => 'USD',
            'is_public' => false,
            'is_bookable' => false,
            'is_package_eligible' => false,
            'status' => $status,
            'main_image' => 'packages/test.jpg',
        ]);
    }

    /** A package that passes PackageService::activate() validation. */
    private function makeActivatablePackage(Company $company, string $status): Package
    {
        $package = $this->makeDraftPackage($company, $status);

        $componentOffer = Offer::create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Hotel component',
            'price' => 500,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        PackageComponent::create([
            'package_id' => $package->id,
            'offer_id' => $componentOffer->id,
            'service_type' => 'hotel',
            'module_type' => 'hotel',
            'package_role' => 'primary',
            'is_required' => true,
        ]);

        return $package->fresh();
    }

    private function superAdmin(): User
    {
        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    public function test_operator_cannot_self_activate_its_first_package(): void
    {
        $company = $this->makeCompany(trusted: false);
        $package = $this->makeDraftPackage($company);
        Sanctum::actingAs($this->makeOperatorFor($company));

        $this->postJson("/api/packages/{$package->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'first_package_requires_review');

        $this->assertSame('draft', $package->fresh()->status);
    }

    public function test_operator_submits_first_package_for_review(): void
    {
        $company = $this->makeCompany(trusted: false);
        $package = $this->makeDraftPackage($company);
        Sanctum::actingAs($this->makeOperatorFor($company));

        $this->postJson("/api/packages/{$package->id}/submit-for-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $this->assertNotNull($package->fresh()->submitted_for_review_at);
    }

    public function test_zulu_admin_rejects_pending_package_with_reason(): void
    {
        $company = $this->makeCompany(trusted: false);
        $package = $this->makeDraftPackage($company, 'pending_review');
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/platform-admin/packages/{$package->id}/reject", [
            'reason' => 'Photos are too low quality.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $fresh = $package->fresh();
        $this->assertSame('Photos are too low quality.', $fresh->rejection_reason);
        $this->assertSame($admin->id, $fresh->reviewed_by);
        // Rejecting must NOT trust the company.
        $this->assertNull($company->fresh()->packages_trusted_at);
    }

    public function test_zulu_admin_approval_activates_package_and_trusts_company(): void
    {
        $company = $this->makeCompany(trusted: false);
        $package = $this->makeActivatablePackage($company, 'pending_review');
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/platform-admin/packages/{$package->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue((bool) $package->fresh()->is_public);
        // First approval flips the company to a trusted self-publisher.
        $this->assertNotNull($company->fresh()->packages_trusted_at);
    }

    public function test_operator_self_publishes_freely_once_company_is_trusted(): void
    {
        $company = $this->makeCompany(trusted: true);
        $package = $this->makeActivatablePackage($company, 'draft');
        Sanctum::actingAs($this->makeOperatorFor($company));

        $this->postJson("/api/packages/{$package->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }
}
