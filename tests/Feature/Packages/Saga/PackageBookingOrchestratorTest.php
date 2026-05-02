<?php

namespace Tests\Feature\Packages\Saga;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Services\Packages\Saga\ComponentReserverRegistry;
use App\Services\Packages\Saga\Contracts\ComponentReserverInterface;
use App\Services\Packages\Saga\DTOs\ConfirmationResult;
use App\Services\Packages\Saga\DTOs\ReservationResult;
use App\Services\Packages\Saga\DTOs\RollbackResult;
use App\Services\Packages\Saga\PackageBookingOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageBookingOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private PackageBookingOrchestrator $orchestrator;

    private ComponentReserverRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(ComponentReserverRegistry::class);
        $this->orchestrator = app(PackageBookingOrchestrator::class);
    }

    public function test_non_package_order_completes_as_no_op_saga(): void
    {
        $order = $this->makeOrder([]);

        $saga = $this->orchestrator->runForOrder($order);

        $this->assertSame('confirmed', $saga->status);
        $this->assertSame(['no_op' => true, 'reason' => 'no package items'], $saga->context);
        $this->assertCount(0, $saga->components);
    }

    public function test_happy_path_reserves_and_confirms_all_components(): void
    {
        $package = $this->makePackageWithComponents(['flight', 'hotel', 'transfer']);
        $order = $this->makeOrder([$package]);

        $saga = $this->orchestrator->runForOrder($order);

        $this->assertSame('confirmed', $saga->status);
        $this->assertNotNull($saga->reserved_at);
        $this->assertNotNull($saga->confirmed_at);
        $this->assertNull($saga->failed_at);

        $components = $saga->fresh()->components;
        $this->assertCount(3, $components);
        foreach ($components as $component) {
            $this->assertSame('confirmed', $component->status);
            $this->assertNotNull($component->supplier_ref);
            $this->assertStringStartsWith('STUB-'.$component->service_type.'-', $component->supplier_ref);
        }

        // Order moved to confirmed
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->items()->first()->status);
    }

    public function test_failure_in_reservation_triggers_rollback_of_all_already_reserved(): void
    {
        // Make hotel reserver always fail; flight succeeds first then hotel fails → flight must be rolled back
        $this->registry->register('hotel', new class implements ComponentReserverInterface
        {
            public function reserve($component, ?OrderItem $item = null): ReservationResult
            {
                return ReservationResult::failure('inventory unavailable');
            }

            public function confirm($component): ConfirmationResult
            {
                return ConfirmationResult::success();
            }

            public function rollback($component): RollbackResult
            {
                return RollbackResult::success();
            }

            public function serviceType(): string
            {
                return 'hotel';
            }
        });

        $package = $this->makePackageWithComponents(['flight', 'hotel', 'transfer']);
        $order = $this->makeOrder([$package]);

        $saga = $this->orchestrator->runForOrder($order);

        $this->assertSame('rolled_back', $saga->status);
        $this->assertNotNull($saga->failed_at);
        $this->assertNotNull($saga->rolled_back_at);
        $this->assertStringContainsString('inventory unavailable', (string) $saga->failure_reason);

        $components = $saga->fresh()->components()->orderBy('id')->get();
        $this->assertSame('rolled_back', $components[0]->status); // flight: was reserved, now rolled back
        $this->assertSame('failed', $components[1]->status);      // hotel: failed
        $this->assertSame('pending', $components[2]->status);     // transfer: never attempted (short-circuit)

        // Order marked failed
        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_idempotent_rerun_returns_existing_terminal_saga(): void
    {
        $package = $this->makePackageWithComponents(['flight']);
        $order = $this->makeOrder([$package]);

        $first = $this->orchestrator->runForOrder($order);
        $this->assertSame('confirmed', $first->status);

        $second = $this->orchestrator->runForOrder($order);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('confirmed', $second->status);
        // No duplicate component states
        $this->assertCount(1, $second->fresh()->components);
    }

    public function test_state_log_records_lifecycle_transitions(): void
    {
        $package = $this->makePackageWithComponents(['flight', 'hotel']);
        $order = $this->makeOrder([$package]);

        $saga = $this->orchestrator->runForOrder($order);

        $events = $saga->fresh()->logs->pluck('event')->all();

        $this->assertContains('created', $events);
        $this->assertContains('reserve_started', $events);
        $this->assertContains('component_reserved', $events);
        $this->assertContains('reserve_complete', $events);
        $this->assertContains('confirmed', $events);
    }

    public function test_state_log_records_rollback_events_on_failure(): void
    {
        $this->registry->register('hotel', new class implements ComponentReserverInterface
        {
            public function reserve($component, ?OrderItem $item = null): ReservationResult
            {
                return ReservationResult::failure('boom');
            }

            public function confirm($component): ConfirmationResult
            {
                return ConfirmationResult::success();
            }

            public function rollback($component): RollbackResult
            {
                return RollbackResult::success();
            }

            public function serviceType(): string
            {
                return 'hotel';
            }
        });

        $package = $this->makePackageWithComponents(['flight', 'hotel']);
        $order = $this->makeOrder([$package]);

        $saga = $this->orchestrator->runForOrder($order);
        $events = $saga->fresh()->logs->pluck('event')->all();

        $this->assertContains('rollback_started', $events);
        $this->assertContains('component_rolled_back', $events);
        $this->assertContains('rolled_back', $events);
    }

    /**
     * @param  array<int, string>  $serviceTypes
     */
    private function makePackageWithComponents(array $serviceTypes): Package
    {
        $company = Company::query()->create([
            'name' => 'Saga Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);

        $packageOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Package Offer '.str()->random(4),
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Test Package '.str()->random(4),
            'duration_days' => 5,
            'min_nights' => 4,
            'adults_count' => 2,
            'children_count' => 0,
            'infants_count' => 0,
            'base_price' => 1000,
            'display_price_mode' => 'total',
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => true,
            'is_package_eligible' => true,
            'status' => 'active',
        ]);

        foreach ($serviceTypes as $i => $type) {
            $offer = Offer::query()->create([
                'company_id' => $company->id,
                'type' => $type,
                'title' => 'Offer '.$type,
                'price' => 100,
                'currency' => 'USD',
                'status' => 'active',
            ]);

            PackageComponent::query()->create([
                'package_id' => $package->id,
                'offer_id' => $offer->id,
                'module_type' => $type,
                'package_role' => $type === 'flight' ? 'flight' : ($type === 'hotel' ? 'stay' : 'transfer'),
                'service_type' => $type,
                'service_id' => $i + 100, // pretend supplier-side ID
                'is_required' => true,
                'sort_order' => $i,
                'selection_mode' => 'fixed',
            ]);
        }

        return $package->fresh();
    }

    /**
     * @param  array<int, Package>  $packages
     */
    private function makeOrder(array $packages): Order
    {
        $order = Order::query()->create([
            'order_number' => 'ORD-SAGA-TEST-'.str()->random(6),
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        foreach ($packages as $package) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'item_type' => 'package',
                'item_id' => $package->id,
                'package_id' => $package->id,
                'quantity' => 1,
                'unit_price' => 1000,
                'total' => 1000,
                'currency' => 'USD',
                'status' => 'pending',
            ]);
        }

        return $order->fresh();
    }
}
