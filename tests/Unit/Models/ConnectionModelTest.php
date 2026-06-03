<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_proposed_connection_with_all_fields(): void
    {
        [$sellerA, $sellerB, $proposer] = $this->makeParties();

        $connection = Connection::query()->create([
            'type' => 'supplier_reseller',
            'seller_a_company_id' => $sellerA->id,
            'seller_b_company_id' => $sellerB->id,
            'direction' => 'a_to_b',
            'share_scope' => ['type' => 'all', 'list' => []],
            'territorial_scope' => ['AM', 'GE'],
            'display_options' => [
                'show_supplier_name' => true,
                'white_label' => false,
                'show_to_price' => true,
                'show_rr_price' => false,
            ],
            'effective_from' => now(),
            'status' => 'proposed',
            'proposed_at' => now(),
            'proposed_by_user_id' => $proposer->id,
        ]);

        $fresh = Connection::query()->findOrFail($connection->id);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $fresh->id);
        $this->assertSame('supplier_reseller', $fresh->type);
        $this->assertSame('a_to_b', $fresh->direction);
        $this->assertSame('proposed', $fresh->status);
        $this->assertTrue($fresh->isProposed());
        $this->assertFalse($fresh->isActive());
        $this->assertSame(['AM', 'GE'], $fresh->territorial_scope);
        $this->assertSame('all', $fresh->share_scope['type']);
        $this->assertTrue($fresh->display_options['show_supplier_name']);
    }

    public function test_relations_load_correctly(): void
    {
        [$sellerA, $sellerB, $proposer] = $this->makeParties();

        $connection = Connection::query()->create([
            'type' => 'mutual',
            'seller_a_company_id' => $sellerA->id,
            'seller_b_company_id' => $sellerB->id,
            'direction' => 'both',
            'share_scope' => ['type' => 'all', 'list' => []],
            'display_options' => [],
            'effective_from' => now(),
            'status' => 'active',
            'proposed_at' => now()->subHour(),
            'accepted_at' => now(),
            'proposed_by_user_id' => $proposer->id,
        ]);

        $fresh = $connection->fresh();

        $this->assertInstanceOf(BelongsTo::class, $fresh->sellerA());
        $this->assertInstanceOf(BelongsTo::class, $fresh->sellerB());
        $this->assertInstanceOf(BelongsTo::class, $fresh->proposedBy());
        $this->assertSame($sellerA->id, $fresh->sellerA->id);
        $this->assertSame($sellerB->id, $fresh->sellerB->id);
        $this->assertSame($proposer->id, $fresh->proposedBy->id);
    }

    public function test_is_active_respects_status_and_effective_to(): void
    {
        [$sellerA, $sellerB, $proposer] = $this->makeParties();

        $base = [
            'type' => 'supplier_reseller',
            'seller_a_company_id' => $sellerA->id,
            'seller_b_company_id' => $sellerB->id,
            'direction' => 'a_to_b',
            'share_scope' => ['type' => 'all', 'list' => []],
            'display_options' => [],
            'effective_from' => now()->subDay(),
            'proposed_at' => now()->subDay(),
            'proposed_by_user_id' => $proposer->id,
        ];

        $proposed = Connection::query()->create($base + ['status' => 'proposed']);
        $active = Connection::query()->create($base + ['status' => 'active']);
        $paused = Connection::query()->create($base + ['status' => 'paused']);
        $expired = Connection::query()->create($base + ['status' => 'active', 'effective_to' => now()->subHour()]);
        $terminated = Connection::query()->create($base + ['status' => 'terminated', 'terminated_at' => now()]);

        $this->assertFalse($proposed->isActive());
        $this->assertTrue($active->isActive());
        $this->assertFalse($paused->isActive());
        $this->assertFalse($expired->isActive());
        $this->assertFalse($terminated->isActive());
        $this->assertTrue($terminated->isTerminated());
        $this->assertTrue($paused->isPaused());
    }

    public function test_involves_and_counterparty(): void
    {
        [$sellerA, $sellerB, $proposer] = $this->makeParties();
        $other = $this->createCompany();

        $connection = Connection::query()->create([
            'type' => 'supplier_reseller',
            'seller_a_company_id' => $sellerA->id,
            'seller_b_company_id' => $sellerB->id,
            'direction' => 'a_to_b',
            'share_scope' => ['type' => 'all', 'list' => []],
            'display_options' => [],
            'effective_from' => now(),
            'status' => 'active',
            'proposed_at' => now(),
            'proposed_by_user_id' => $proposer->id,
        ]);

        $this->assertTrue($connection->involves($sellerA->id));
        $this->assertTrue($connection->involves($sellerB->id));
        $this->assertFalse($connection->involves($other->id));
        $this->assertSame($sellerB->id, $connection->counterpartyOf($sellerA->id));
        $this->assertSame($sellerA->id, $connection->counterpartyOf($sellerB->id));
        $this->assertNull($connection->counterpartyOf($other->id));
    }

    public function test_flows_from_respects_direction(): void
    {
        [$sellerA, $sellerB, $proposer] = $this->makeParties();

        $base = [
            'type' => 'supplier_reseller',
            'seller_a_company_id' => $sellerA->id,
            'seller_b_company_id' => $sellerB->id,
            'share_scope' => ['type' => 'all', 'list' => []],
            'display_options' => [],
            'effective_from' => now(),
            'status' => 'active',
            'proposed_at' => now(),
            'proposed_by_user_id' => $proposer->id,
        ];

        $aToB = Connection::query()->create($base + ['direction' => 'a_to_b']);
        $bToA = Connection::query()->create($base + ['direction' => 'b_to_a']);
        $both = Connection::query()->create($base + ['direction' => 'both']);

        // a_to_b: A is supplier, B is reseller
        $this->assertTrue($aToB->flowsFrom($sellerA->id, $sellerB->id));
        $this->assertFalse($aToB->flowsFrom($sellerB->id, $sellerA->id));

        // b_to_a: B is supplier, A is reseller
        $this->assertFalse($bToA->flowsFrom($sellerA->id, $sellerB->id));
        $this->assertTrue($bToA->flowsFrom($sellerB->id, $sellerA->id));

        // both: either direction
        $this->assertTrue($both->flowsFrom($sellerA->id, $sellerB->id));
        $this->assertTrue($both->flowsFrom($sellerB->id, $sellerA->id));

        // inactive connection never flows
        $inactive = Connection::query()->create(array_merge($base, ['direction' => 'a_to_b', 'status' => 'paused']));
        $this->assertFalse($inactive->flowsFrom($sellerA->id, $sellerB->id));
    }

    /**
     * @return array{0: Company, 1: Company, 2: User}
     */
    private function makeParties(): array
    {
        return [
            $this->createCompany(),
            $this->createCompany(),
            User::factory()->create(),
        ];
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Connection Test Co '.str()->random(6),
            'type' => 'operator',
        ]);
    }
}
