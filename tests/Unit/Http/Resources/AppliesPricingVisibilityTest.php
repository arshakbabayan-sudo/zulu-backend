<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\Api\Concerns\AppliesPricingVisibility;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 / Step B.3 — verifies the AppliesPricingVisibility trait
 * properly gates supplier-net price visibility:
 *   - super_admin: sees base_price for any offer
 *   - operator owning the offer: sees base_price for own offers only
 *   - operator of OTHER company: does NOT see base_price (until partnership wired)
 *   - unauthenticated request: NEVER sees base_price
 *
 * Verifies both safePricing() (object payload) and safeBasePrice()
 * (top-level field flavour).
 */
class AppliesPricingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // Concrete consumer of the trait so the protected methods can be
        // invoked through public proxies. Mirrors how a Resource uses it.
        $this->subject = new class
        {
            use AppliesPricingVisibility {
                canSeeNetPrice as public;
                safePricing as public;
                safeBasePrice as public;
            }
        };
    }

    private function seedRoles(): void
    {
        foreach (['super_admin' => 'platform', 'company_admin' => 'company'] as $name => $scope) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                ['name' => $name, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    private function makeCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'TestCo '.uniqid(),
            'type' => 'operator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asUser(?User $user): Request
    {
        $req = Request::create('/foo', 'GET');
        if ($user !== null) {
            $req->setUserResolver(fn () => $user);
        }

        return $req;
    }

    public function test_unauth_request_never_sees_base_price(): void
    {
        $payload = $this->subject->safePricing($this->asUser(null), 100.0, 'USD', 42);
        $this->assertArrayHasKey('sell_price', $payload);
        $this->assertArrayHasKey('currency', $payload);
        $this->assertArrayNotHasKey('base_price', $payload);

        $this->assertNull(
            $this->subject->safeBasePrice($this->asUser(null), 100.0, 42),
            'safeBasePrice() must return null for unauthenticated requests'
        );
    }

    public function test_super_admin_sees_base_price_for_any_offer(): void
    {
        $this->seedRoles();
        $superRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $superAdmin = User::factory()->create();
        $someCompany = $this->makeCompany();
        $unrelatedCompany = $this->makeCompany();

        UserCompany::create([
            'user_id' => $superAdmin->id,
            'company_id' => $someCompany,
            'role_id' => $superRoleId,
        ]);
        $superAdmin->refresh();

        $req = $this->asUser($superAdmin);

        $payload = $this->subject->safePricing($req, 100.0, 'USD', $unrelatedCompany);
        $this->assertArrayHasKey('base_price', $payload);
        $this->assertSame(100.0, $payload['base_price']);

        $this->assertSame(
            100.0,
            $this->subject->safeBasePrice($req, 100.0, $unrelatedCompany)
        );
    }

    public function test_owning_operator_sees_base_price_for_own_offer(): void
    {
        $this->seedRoles();
        $companyAdminRoleId = DB::table('roles')->where('name', 'company_admin')->value('id');

        $companyId = $this->makeCompany();
        $operator = User::factory()->create();
        UserCompany::create([
            'user_id' => $operator->id,
            'company_id' => $companyId,
            'role_id' => $companyAdminRoleId,
        ]);
        $operator->refresh();

        $req = $this->asUser($operator);

        $payload = $this->subject->safePricing($req, 100.0, 'USD', $companyId);
        $this->assertArrayHasKey('base_price', $payload);
        $this->assertSame(100.0, $payload['base_price']);
    }

    public function test_operator_of_other_company_does_not_see_base_price(): void
    {
        $this->seedRoles();
        $companyAdminRoleId = DB::table('roles')->where('name', 'company_admin')->value('id');

        $ownCompany = $this->makeCompany();
        $otherCompany = $this->makeCompany();
        $otherOperator = User::factory()->create();
        UserCompany::create([
            'user_id' => $otherOperator->id,
            'company_id' => $ownCompany,
            'role_id' => $companyAdminRoleId,
        ]);
        $otherOperator->refresh();

        $req = $this->asUser($otherOperator);

        $payload = $this->subject->safePricing($req, 100.0, 'USD', $otherCompany);
        $this->assertArrayNotHasKey(
            'base_price',
            $payload,
            'Operator of OTHER company must not see supplier net (until partnership feature wired)'
        );
    }

    public function test_sell_price_is_always_present_and_applies_markup(): void
    {
        $payload = $this->subject->safePricing($this->asUser(null), 100.0, 'USD', null);

        // Default markup from PlatformSettingsService is 15% (seeded).
        // 100 * 1.15 = 115.0
        $this->assertSame(115.0, $payload['sell_price']);
        $this->assertSame(115.0, $payload['calculated_price']); // back-compat alias
        $this->assertSame('USD', $payload['currency']);
    }
}
