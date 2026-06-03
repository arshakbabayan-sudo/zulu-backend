<?php

namespace Tests\Feature\Partnerships;

use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use App\Services\Partnerships\ConnectionVisibilityResolver;
use App\Services\Partnerships\PartnerConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionVisibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionVisibilityResolver $resolver;

    private PartnerConnectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(ConnectionVisibilityResolver::class);
        $this->service = app(PartnerConnectionService::class);
    }

    public function test_no_connection_returns_no_visibility(): void
    {
        $supplier = $this->makeCompany();
        $viewer = $this->makeCompany();

        $this->assertNull($this->resolver->applicableConnection($viewer, $supplier));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'hotel'));
        $this->assertSame([], $this->resolver->visibleSupplierIds($viewer));
    }

    public function test_proposed_connection_does_not_grant_visibility(): void
    {
        [$supplier, $viewer, $connection] = $this->setupProposed();

        $this->assertNull($this->resolver->applicableConnection($viewer, $supplier));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'hotel'));
    }

    public function test_active_a_to_b_connection_grants_visibility_to_b_only(): void
    {
        [$supplier, $viewer, $connection] = $this->setupActive(['type' => 'supplier_reseller', 'direction' => 'a_to_b']);

        // viewer (B) sees supplier (A)
        $this->assertNotNull($this->resolver->applicableConnection($viewer, $supplier));
        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel'));

        // supplier (A) does NOT see viewer (B) — direction is one-way
        $this->assertNull($this->resolver->applicableConnection($supplier, $viewer));
        $this->assertFalse($this->resolver->isServiceVisible($supplier, $viewer, 'hotel'));
    }

    public function test_active_both_direction_grants_mutual_visibility(): void
    {
        [$a, $b, $connection] = $this->setupActive(['type' => 'mutual', 'direction' => 'both']);

        $this->assertTrue($this->resolver->isServiceVisible($a, $b, 'hotel'));
        $this->assertTrue($this->resolver->isServiceVisible($b, $a, 'hotel'));
    }

    public function test_share_scope_all_visible_for_any_service_type(): void
    {
        [$supplier, $viewer] = $this->setupActive([
            'share_scope' => ['type' => 'all', 'list' => []],
        ]);

        foreach (['flight', 'hotel', 'transfer', 'car', 'excursion'] as $type) {
            $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, $type));
        }
    }

    public function test_share_scope_categories_filters_by_service_type(): void
    {
        [$supplier, $viewer] = $this->setupActive([
            'share_scope' => ['type' => 'categories', 'list' => ['hotel', 'transfer']],
        ]);

        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel'));
        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'transfer'));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'flight'));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'excursion'));
    }

    public function test_share_scope_services_filters_by_individual_id(): void
    {
        [$supplier, $viewer] = $this->setupActive([
            'share_scope' => ['type' => 'services', 'list' => [42, 'hotel:99']],
        ]);

        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel', 42));
        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel', 99));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'hotel', 1));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'hotel')); // no id provided
    }

    public function test_territorial_scope_filters_when_service_country_known(): void
    {
        [$supplier, $viewer] = $this->setupActive([
            'territorial_scope' => ['AM', 'GE'],
        ]);

        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel', null, ['AM']));
        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel', null, ['GE', 'RU']));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'hotel', null, ['RU']));
    }

    public function test_territorial_scope_permissive_when_country_unknown(): void
    {
        [$supplier, $viewer] = $this->setupActive([
            'territorial_scope' => ['AM'],
        ]);

        // service country unknown → don't block
        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel'));
        $this->assertTrue($this->resolver->isServiceVisible($viewer, $supplier, 'hotel', null, []));
    }

    public function test_paused_or_terminated_connection_does_not_grant_visibility(): void
    {
        [$supplier, $viewer, $connection] = $this->setupActive();
        $this->service->pause($connection, User::factory()->create());

        $this->assertNull($this->resolver->applicableConnection($viewer, $supplier));
        $this->assertFalse($this->resolver->isServiceVisible($viewer, $supplier, 'hotel'));
    }

    public function test_expired_connection_does_not_grant_visibility(): void
    {
        $supplier = $this->makeCompany();
        $viewer = $this->makeCompany();
        $proposer = User::factory()->create();
        $accepter = User::factory()->create();

        $connection = $this->service->propose($supplier, $viewer, $proposer, [
            'effective_from' => now()->subDays(10),
            'effective_to' => now()->subDay(),
        ]);
        $this->service->accept($connection, $accepter);

        $this->assertNull($this->resolver->applicableConnection($viewer, $supplier));
    }

    public function test_visible_supplier_ids_lists_all_active_partners(): void
    {
        $viewer = $this->makeCompany();
        $supplier1 = $this->makeCompany();
        $supplier2 = $this->makeCompany();
        $supplier3 = $this->makeCompany();
        $proposer = User::factory()->create();
        $accepter = User::factory()->create();

        // active a_to_b: supplier1 → viewer
        $c1 = $this->service->propose($supplier1, $viewer, $proposer);
        $this->service->accept($c1, $accepter);

        // active a_to_b: supplier2 → viewer
        $c2 = $this->service->propose($supplier2, $viewer, $proposer);
        $this->service->accept($c2, $accepter);

        // proposed only: supplier3 — should NOT appear
        $this->service->propose($supplier3, $viewer, $proposer);

        $visible = $this->resolver->visibleSupplierIds($viewer);
        sort($visible);

        $expected = [$supplier1->id, $supplier2->id];
        sort($expected);

        $this->assertSame($expected, $visible);
    }

    public function test_visible_service_types_returns_categories_or_null(): void
    {
        [$supplier, $viewer] = $this->setupActive([
            'share_scope' => ['type' => 'categories', 'list' => ['hotel', 'flight']],
        ]);

        $this->assertSame(['hotel', 'flight'], $this->resolver->visibleServiceTypes($viewer, $supplier));

        [$s2, $v2] = $this->setupActive([
            'share_scope' => ['type' => 'all', 'list' => []],
        ]);
        $this->assertNull($this->resolver->visibleServiceTypes($v2, $s2));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Company, 1: Company, 2: Connection}
     */
    private function setupActive(array $overrides = []): array
    {
        $supplier = $this->makeCompany();
        $viewer = $this->makeCompany();
        $proposer = User::factory()->create();
        $accepter = User::factory()->create();

        $connection = $this->service->propose($supplier, $viewer, $proposer, $overrides);
        $connection = $this->service->accept($connection, $accepter);

        return [$supplier, $viewer, $connection];
    }

    /**
     * @return array{0: Company, 1: Company, 2: Connection}
     */
    private function setupProposed(): array
    {
        $supplier = $this->makeCompany();
        $viewer = $this->makeCompany();
        $proposer = User::factory()->create();

        $connection = $this->service->propose($supplier, $viewer, $proposer);

        return [$supplier, $viewer, $connection];
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Visibility Test Co '.str()->random(6),
            'type' => 'operator',
        ]);
    }
}
