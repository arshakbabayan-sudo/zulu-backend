<?php

namespace Tests\Feature\Sprint1;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\PackageOrder;
use App\Models\User;
use App\Services\Commissions\CommissionService;
use App\Services\Finance\FinanceService;
use App\Services\Invoices\InvoiceService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Packages\PackageOrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PackageOrderMirrorStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_paid_syncs_mirror_order_status_only(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackageWithComponents($company, true);

        $packageOrder = $this->makeService()->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $mirrorOrder = Order::query()->findOrFail($packageOrder->mirror_order_id);
        $this->assertSame('pending_payment', $mirrorOrder->status);
        $beforeItemStatuses = $mirrorOrder->items()->orderBy('id')->pluck('status')->all();

        $this->makeService()->markPaid($packageOrder->fresh());

        $mirrorOrder->refresh();
        $this->assertSame('paid', $mirrorOrder->status);
        $this->assertSame(
            $beforeItemStatuses,
            $mirrorOrder->items()->orderBy('id')->pluck('status')->all()
        );
    }

    public function test_cancel_order_syncs_mirror_order_and_items_to_cancelled(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackageWithComponents($company, false);

        $packageOrder = $this->makeService()->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $this->assertSame('draft', $packageOrder->status);

        $this->makeService()->cancelOrder($packageOrder->fresh());

        $mirrorOrder = Order::query()->findOrFail($packageOrder->mirror_order_id);
        $this->assertSame('cancelled', $mirrorOrder->status);
        $this->assertTrue(
            $mirrorOrder->items()->get()->every(
                fn (OrderItem $item): bool => $item->status === 'cancelled'
            )
        );
    }

    public function test_mark_paid_with_null_mirror_order_id_succeeds(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackageWithComponents($company, true);

        $legacyOrder = PackageOrder::query()->create([
            'package_id' => $package->id,
            'user_id' => $user->id,
            'company_id' => $company->id,
            'order_number' => 'PKG-NULL-'.str()->upper(str()->random(6)),
            'booking_channel' => 'public_b2c',
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'adults_count' => 1,
            'children_count' => 0,
            'infants_count' => 0,
            'currency' => 'USD',
            'base_component_total_snapshot' => 100.00,
            'discount_snapshot' => 0,
            'markup_snapshot' => 0,
            'addon_total_snapshot' => 0,
            'final_total_snapshot' => 100.00,
            'display_price_mode_snapshot' => 'total',
        ]);

        $legacyOrder->mirror_order_id = null;
        $legacyOrder->save();

        $this->makeService()->markPaid($legacyOrder);

        $fresh = $legacyOrder->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('paid', $fresh->payment_status);
    }

    public function test_mark_paid_swallows_mirror_sync_failure_and_keeps_legacy_mutation(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackageWithComponents($company, true);

        $packageOrder = $this->makeService()->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $service = new class(app(InvoiceService::class), app(PaymentService::class), app(CommissionService::class), app(NotificationService::class), app(FinanceService::class), app(OrderService::class)) extends PackageOrderService
        {
            protected function syncMirrorOrderStatus(PackageOrder $packageOrder, string $orderStatus, ?string $itemStatus = null): void
            {
                throw new RuntimeException('forced mirror sync failure');
            }
        };

        $service->markPaid($packageOrder->fresh());

        $fresh = $packageOrder->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('paid', $fresh->payment_status);
    }

    private function makeService(): PackageOrderService
    {
        return new PackageOrderService(
            app(InvoiceService::class),
            app(PaymentService::class),
            app(CommissionService::class),
            app(NotificationService::class),
            app(FinanceService::class),
            app(OrderService::class)
        );
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Package Mirror Sync Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Package Mirror Sync User',
            'email' => 'package-mirror-sync-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createPackageWithComponents(Company $company, bool $isBookable): Package
    {
        $packageOffer = $this->createOffer($company, 'package', 200.00);
        $flightOffer = $this->createOffer($company, 'flight', 120.00);
        $hotelOffer = $this->createOffer($company, 'hotel', 80.00);

        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Mirror Sync Package '.str()->uuid(),
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => $isBookable,
            'status' => 'active',
        ]);

        PackageComponent::query()->create([
            'package_id' => $package->id,
            'offer_id' => $flightOffer->id,
            'module_type' => 'flight',
            'package_role' => 'flight',
            'is_required' => true,
            'sort_order' => 1,
            'selection_mode' => 'fixed',
            'price_override' => 120.00,
        ]);

        PackageComponent::query()->create([
            'package_id' => $package->id,
            'offer_id' => $hotelOffer->id,
            'module_type' => 'hotel',
            'package_role' => 'stay',
            'is_required' => true,
            'sort_order' => 2,
            'selection_mode' => 'fixed',
            'price_override' => 80.00,
        ]);

        return $package;
    }

    private function createOffer(Company $company, string $type, float $price): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'title' => strtoupper($type).' Offer '.str()->uuid(),
            'price' => $price,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
    }
}
