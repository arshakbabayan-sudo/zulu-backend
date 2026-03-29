<?php

namespace App\Services\Packages;

use App\Models\Offer;
use App\Models\Package;
use App\Models\PackageOrder;
use App\Models\PackageOrderItem;
use App\Models\User;
use App\Services\Commissions\CommissionService;
use App\Services\Finance\FinanceService;
use App\Services\Invoices\InvoiceService;
use App\Services\Notifications\NotificationService;
use App\Services\Payments\PaymentService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PackageOrderService
{
    public function __construct(
        private InvoiceService $invoiceService,
        private PaymentService $paymentService,
        private CommissionService $commissionService,
        private NotificationService $notificationService,
        private FinanceService $financeService
    ) {}

    public function createOrder(Package $package, User $user, array $input): PackageOrder
    {
        $adultsCount = max(1, (int) ($input['adults_count'] ?? 1));
        $childrenCount = max(0, (int) ($input['children_count'] ?? 0));
        $infantsCount = max(0, (int) ($input['infants_count'] ?? 0));
        $bookingChannel = (string) ($input['booking_channel'] ?? 'public_b2c');
        $notes = isset($input['notes']) ? (string) $input['notes'] : null;

        if (! in_array($bookingChannel, PackageOrder::BOOKING_CHANNELS, true)) {
            throw ValidationException::withMessages([
                'booking_channel' => ['Invalid booking channel.'],
            ]);
        }

        return DB::transaction(function () use ($package, $user, $adultsCount, $childrenCount, $infantsCount, $bookingChannel, $notes) {
            $package->loadMissing(['components.offer', 'offer']);

            if ($package->status !== 'active' || ! $package->is_public) {
                throw ValidationException::withMessages([
                    'package_id' => ['Package is not available for booking.'],
                ]);
            }

            $components = $package->components->sortBy('sort_order')->values();
            $requiredComponents = $components->where('is_required', true);

            if ($requiredComponents->isEmpty()) {
                throw ValidationException::withMessages([
                    'package_id' => ['Package has no bookable required components.'],
                ]);
            }

            foreach ($components as $component) {
                $offer = $component->offer;
                if ($offer === null) {
                    if ($component->is_required) {
                        throw ValidationException::withMessages([
                            'package_id' => [
                                sprintf(
                                    'Required component "%s" is missing a linked offer.',
                                    $component->module_type
                                ),
                            ],
                        ]);
                    }

                    continue;
                }
                if ($component->is_required && $offer->status !== Offer::STATUS_PUBLISHED) {
                    throw ValidationException::withMessages([
                        'package_id' => [
                            sprintf(
                                'Required component "%s" (offer #%d) is not published.',
                                $component->module_type,
                                $offer->id
                            ),
                        ],
                    ]);
                }
            }

            $baseComponentTotal = '0';
            foreach ($components as $component) {
                $offer = $component->offer;
                if ($offer === null) {
                    throw ValidationException::withMessages([
                        'package_id' => ['Package component is missing a linked offer.'],
                    ]);
                }
                $line = (string) ($component->price_override ?? $offer->price);
                $baseComponentTotal = bcadd($baseComponentTotal, $line, 2);
            }

            $currency = $package->currency ?? $package->offer?->currency;
            if ($currency === null || $currency === '') {
                throw ValidationException::withMessages([
                    'package_id' => ['Package currency could not be determined.'],
                ]);
            }

            $nextId = (int) (PackageOrder::query()->max('id') ?? 0) + 1;
            $orderNumber = 'PKG-'.str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);

            $isBookable = (bool) $package->is_bookable;
            $orderStatus = $isBookable ? 'pending_payment' : 'draft';

            /** @var PackageOrder $packageOrder */
            $packageOrder = PackageOrder::query()->create([
                'package_id' => $package->id,
                'user_id' => $user->id,
                'company_id' => $package->company_id,
                'order_number' => $orderNumber,
                'booking_channel' => $bookingChannel,
                'status' => $orderStatus,
                'payment_status' => 'unpaid',
                'adults_count' => $adultsCount,
                'children_count' => $childrenCount,
                'infants_count' => $infantsCount,
                'currency' => $currency,
                'base_component_total_snapshot' => $baseComponentTotal,
                'discount_snapshot' => 0,
                'markup_snapshot' => 0,
                'addon_total_snapshot' => 0,
                'final_total_snapshot' => $baseComponentTotal,
                'display_price_mode_snapshot' => $package->display_price_mode ?? 'total',
                'notes' => $notes,
            ]);

            foreach ($components as $component) {
                $offer = $component->offer;
                $linePrice = $component->price_override ?? $offer->price;
                $itemCurrency = $package->currency ?? $offer->currency ?? $currency;

                PackageOrderItem::query()->create([
                    'package_order_id' => $packageOrder->id,
                    'package_component_id' => $component->id,
                    'offer_id' => $component->offer_id,
                    'module_type' => $component->module_type,
                    'package_role' => $component->package_role,
                    'company_id' => $offer->company_id,
                    'is_required' => $component->is_required,
                    'price_snapshot' => $linePrice,
                    'currency_snapshot' => $itemCurrency,
                    'status' => 'pending',
                    'sort_order' => $component->sort_order,
                ]);
            }

            return $packageOrder->load(['items.offer', 'items.company', 'package']);
        });
    }

    public function markPaid(PackageOrder $order): PackageOrder
    {
        return DB::transaction(function () use ($order) {
            $this->assertOrderTransitionAllowed($order, 'paid');

            $order->status = 'paid';
            $order->payment_status = 'paid';
            $order->save();

            $invoice = $this->invoiceService->createForPackageOrder($order);
            $payment = $this->paymentService->createForPackageOrderInvoice($invoice);
            $this->paymentService->markPaid($payment);
            $this->invoiceService->markPaid($invoice);

            try {
                $this->commissionService->accrueForPackageOrder($order->fresh());
            } catch (\Throwable $e) {
                Log::warning('Package order commission accrual failed', [
                    'package_order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                if ($order->user_id !== null) {
                    $this->notificationService->createForEvent([
                        'user_id' => (int) $order->user_id,
                        'event_type' => 'package_order.paid',
                        'title' => 'Payment Successful',
                        'message' => 'Your order '.$order->order_number.' has been paid successfully.',
                        'subject_type' => 'package_order',
                        'subject_id' => $order->id,
                        'priority' => 'high',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Package order paid notification failed', [
                    'package_order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $this->financeService->createEntitlementsForOrder($order->fresh(['items.offer']));
            } catch (\Throwable $e) {
                Log::warning('Entitlement creation failed for package order', [
                    'package_order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            return $order->fresh(['items.offer', 'items.company', 'package']);
        });
    }

    public function confirmItem(PackageOrder $order, int $itemId): PackageOrderItem
    {
        $item = $order->items()->whereKey($itemId)->first();
        if ($item === null) {
            throw ValidationException::withMessages([
                'item' => ['Order item not found.'],
            ]);
        }

        $item->status = 'confirmed';
        $item->save();

        $this->recalculateOrderStatus($order->fresh(['items']));

        return $item->fresh(['offer', 'company']);
    }

    public function failItem(PackageOrder $order, int $itemId, string $reason): PackageOrderItem
    {
        $item = $order->items()->whereKey($itemId)->first();
        if ($item === null) {
            throw ValidationException::withMessages([
                'item' => ['Order item not found.'],
            ]);
        }

        $item->status = 'failed';
        $item->failure_reason = $reason;
        $item->save();

        $this->recalculateOrderStatus($order->fresh(['items']));

        return $item->fresh(['offer', 'company']);
    }

    public function cancelOrder(PackageOrder $order): PackageOrder
    {
        return DB::transaction(function () use ($order) {
            $this->assertOrderTransitionAllowed($order, 'cancelled');

            PackageOrderItem::query()
                ->where('package_order_id', $order->id)
                ->update(['status' => 'cancelled']);

            $order->status = 'cancelled';
            $order->save();

            return $order->fresh(['items.offer', 'items.company', 'package', 'user']);
        });
    }

    public function listForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return PackageOrder::query()
            ->where('user_id', $user->id)
            ->with(['package', 'items'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function listForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        return PackageOrder::query()
            ->whereIn('company_id', $companyIds)
            ->with(['package', 'user', 'items'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForUser(int $orderId, User $user): ?PackageOrder
    {
        return PackageOrder::query()
            ->where('user_id', $user->id)
            ->whereKey($orderId)
            ->with(['package', 'items.offer', 'items.company'])
            ->first();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int $orderId, array $companyIds): ?PackageOrder
    {
        return PackageOrder::query()
            ->whereIn('company_id', $companyIds)
            ->whereKey($orderId)
            ->with(['package', 'user', 'items.offer', 'items.company'])
            ->first();
    }

    private function assertOrderTransitionAllowed(PackageOrder $order, string $targetStatus): void
    {
        /** @var array<string, list<string>> $allowed */
        $allowed = [
            'draft' => ['pending_payment', 'cancelled'],
            'pending_payment' => ['paid', 'cancelled', 'draft'],
            'paid' => ['partially_confirmed', 'confirmed', 'partially_failed', 'cancelled'],
            'partially_confirmed' => ['confirmed', 'partially_failed', 'cancelled'],
            'confirmed' => ['completed', 'cancelled'],
            'partially_failed' => ['cancelled', 'confirmed'],
            'completed' => [],
            'cancelled' => [],
        ];

        $current = $order->status;
        $next = $allowed[$current] ?? [];

        if (! in_array($targetStatus, $next, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition order from {$current} to {$targetStatus}."],
            ]);
        }
    }

    private function recalculateOrderStatus(PackageOrder $order): void
    {
        if (! $order->relationLoaded('items')) {
            $order->load('items');
        }

        $items = $order->items;

        $newStatus = $order->status;

        if ($items->isNotEmpty() && $items->every(fn (PackageOrderItem $i) => $i->status === 'confirmed')) {
            $newStatus = 'confirmed';
        } elseif ($items->contains(fn (PackageOrderItem $i) => $i->is_required && $i->status === 'failed')) {
            $newStatus = 'partially_failed';
        } elseif (
            $items->contains(fn (PackageOrderItem $i) => $i->status === 'confirmed')
            && $items->every(fn (PackageOrderItem $i) => in_array($i->status, ['confirmed', 'pending', 'awaiting_supplier'], true))
        ) {
            $newStatus = 'partially_confirmed';
        }

        if ($newStatus !== $order->status) {
            $order->status = $newStatus;
            $order->save();
        }
    }
}
