<?php

namespace Tests\Feature\Partnerships;

use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use App\Services\Partnerships\PartnerConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PartnerConnectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerConnectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PartnerConnectionService::class);
    }

    public function test_propose_creates_connection_in_proposed_state(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $proposer = User::factory()->create();

        $connection = $this->service->propose($a, $b, $proposer, [
            'type' => 'supplier_reseller',
            'direction' => 'a_to_b',
            'territorial_scope' => ['AM'],
            'share_scope' => ['type' => 'categories', 'list' => ['flight', 'hotel']],
        ]);

        $this->assertSame('proposed', $connection->status);
        $this->assertSame('a_to_b', $connection->direction);
        $this->assertSame($a->id, $connection->seller_a_company_id);
        $this->assertSame($b->id, $connection->seller_b_company_id);
        $this->assertSame($proposer->id, $connection->proposed_by_user_id);
        $this->assertSame('categories', $connection->share_scope['type']);
        $this->assertSame(['flight', 'hotel'], $connection->share_scope['list']);
        $this->assertSame(['AM'], $connection->territorial_scope);
        $this->assertTrue($connection->display_options['show_supplier_name']);
    }

    public function test_propose_rejects_self_connection(): void
    {
        $a = $this->makeCompany();
        $proposer = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service->propose($a, $a, $proposer);
    }

    public function test_propose_rejects_invalid_type(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $proposer = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service->propose($a, $b, $proposer, ['type' => 'bogus']);
    }

    public function test_propose_rejects_duplicate_open_connection(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $proposer = User::factory()->create();

        $this->service->propose($a, $b, $proposer);

        $this->expectException(RuntimeException::class);
        $this->service->propose($a, $b, $proposer);
    }

    public function test_propose_rejects_duplicate_in_either_direction(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $proposer = User::factory()->create();

        $this->service->propose($a, $b, $proposer);

        // Same pair, A and B swapped — should still block
        $this->expectException(RuntimeException::class);
        $this->service->propose($b, $a, $proposer);
    }

    public function test_accept_moves_proposed_to_active(): void
    {
        $connection = $this->makeProposed();
        $accepter = User::factory()->create();

        $accepted = $this->service->accept($connection, $accepter);

        $this->assertSame('active', $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame($accepter->id, $accepted->responded_by_user_id);
        $this->assertTrue($accepted->isActive());
    }

    public function test_accept_rejects_non_proposed_connection(): void
    {
        $connection = $this->makeProposed();
        $accepter = User::factory()->create();
        $this->service->accept($connection, $accepter);

        $this->expectException(RuntimeException::class);
        $this->service->accept($connection->fresh(), $accepter);
    }

    public function test_reject_moves_proposed_to_rejected_with_reason(): void
    {
        $connection = $this->makeProposed();
        $rejecter = User::factory()->create();

        $rejected = $this->service->reject($connection, $rejecter, 'Not aligned with our markets');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Not aligned with our markets', $rejected->rejection_reason);
        $this->assertSame($rejecter->id, $rejected->responded_by_user_id);
    }

    public function test_counter_swaps_proposer_and_updates_terms(): void
    {
        $connection = $this->makeProposed();
        $originalProposer = $connection->proposed_by_user_id;
        $counterer = User::factory()->create();

        $countered = $this->service->counter($connection, $counterer, [
            'direction' => 'both',
            'territorial_scope' => ['AM', 'GE', 'RU'],
            'share_scope' => ['type' => 'all', 'list' => []],
        ]);

        $this->assertSame('proposed', $countered->status); // still proposed
        $this->assertSame('both', $countered->direction);
        $this->assertSame(['AM', 'GE', 'RU'], $countered->territorial_scope);
        $this->assertSame($counterer->id, $countered->proposed_by_user_id);
        $this->assertSame($originalProposer, $countered->responded_by_user_id);
    }

    public function test_pause_and_resume_cycle(): void
    {
        $connection = $this->makeActive();
        $user = User::factory()->create();

        $paused = $this->service->pause($connection, $user);
        $this->assertSame('paused', $paused->status);
        $this->assertNotNull($paused->paused_at);
        $this->assertFalse($paused->isActive());

        $resumed = $this->service->resume($paused, $user);
        $this->assertSame('active', $resumed->status);
        $this->assertNull($resumed->paused_at);
        $this->assertTrue($resumed->isActive());
    }

    public function test_cannot_pause_proposed_connection(): void
    {
        $connection = $this->makeProposed();
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->service->pause($connection, $user);
    }

    public function test_terminate_active_connection_with_reason(): void
    {
        $connection = $this->makeActive();
        $user = User::factory()->create();

        $terminated = $this->service->terminate($connection, $user, 'End of agreement period');

        $this->assertSame('terminated', $terminated->status);
        $this->assertNotNull($terminated->terminated_at);
        $this->assertSame('End of agreement period', $terminated->termination_reason);
        $this->assertTrue($terminated->isTerminated());
    }

    public function test_terminate_paused_connection_works(): void
    {
        $connection = $this->makeActive();
        $user = User::factory()->create();
        $paused = $this->service->pause($connection, $user);

        $terminated = $this->service->terminate($paused, $user, 'Decision to end');
        $this->assertSame('terminated', $terminated->status);
    }

    public function test_terminate_requires_non_empty_reason(): void
    {
        $connection = $this->makeActive();
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service->terminate($connection, $user, '   ');
    }

    public function test_cannot_terminate_proposed_connection(): void
    {
        $connection = $this->makeProposed();
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->service->terminate($connection, $user, 'too early');
    }

    private function makeProposed(): Connection
    {
        return $this->service->propose(
            $this->makeCompany(),
            $this->makeCompany(),
            User::factory()->create(),
        );
    }

    private function makeActive(): Connection
    {
        $proposed = $this->makeProposed();

        return $this->service->accept($proposed, User::factory()->create());
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Partner Test Co '.str()->random(6),
            'type' => 'operator',
        ]);
    }
}
