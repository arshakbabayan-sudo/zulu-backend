<?php

namespace App\Services\Packages;

use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\User;
use App\Services\Commissions\CommissionService;
use App\Services\Finance\FinanceService;
use App\Services\Invoices\InvoiceService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use App\Services\Vouchers\VoucherService;
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
        private FinanceService $financeService,
        private OrderService $orderService,
        private VoucherService $voucherService
    ) {}

    public function createOrder(Package $package, User $user, array $input): Order
    {
        $adultsCount = max(1, (int) ($input['adults_count'] ?? 1));
        $childrenCount = max(0, (int) ($input['children_count'] ?? 0));
        $infantsCount = max(0, (int) ($input['infants_count'] ?? 0));
        $bookingChannel = (string) ($input['booking_channel'] ?? 'public_b2c');
        $notes = isset($input['notes']) ? (string) $input['notes'] : null;

        if (! in_array($bookingChannel, Order::BOOKING_CHANNELS, true)) {
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

            $nextId = Order::query()
                ->where('metadata->legacy_origin', 'package_order')
                ->count() + 1;
            $orderNumber = 'PKG-'.str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);

            $isBookable = (bool) $package->is_bookable;
            $orderStatus = $isBookable ? 'pending_payment' : 'cart';

            $order = $this->orderService->create(
                [
                    'user_id' => $user->id,
                    'company_id' => $package->company_id,
                    'currency' => $currency,
                    'order_number' => $orderNumber,
                    'buyer_type' => 'client',
                    'status' => $orderStatus,
                    'notes' => $notes,
                    'metadata' => [
                        'legacy_origin' => 'package_order',
                        'booking_channel' => $bookingChannel,
                        'adults_count' => $adultsCount,
                        'children_count' => $childrenCount,
                        'infants_count' => $infantsCount,
                    ],
                ],
                $components->map(function ($component) use ($package, $currency): array {
                    $offer = $component->offer;
                    $linePrice = $component->price_override ?? $offer->price;
                    $itemCurrency = $package->currency ?? $offer->currency ?? $currency;

                    return [
                        'item_type' => $component->module_type,
                        'item_id' => $this->resolveOfferItemId($offer, $component->module_type),
                        'package_id' => $package->id,
                        'unit_price' => $linePrice,
                        'currency' => $itemCurrency,
                        'service_snapshot' => [
                            'legacy_offer_id' => $offer->id,
                            'legacy_component_id' => $component->id,
                            'package_role' => $component->package_role,
                            'sort_order' => $component->sort_order,
                            'is_required' => $component->is_required,
                        ],
                    ];
                })->values()->all()
            );

            return $order;
        });
    }

    public function markPaid(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->assertOrderTransitionAllowed($order, 'paid');

            $order->status = 'paid';
            $order->save();

            $invoice = $this->invoiceService->createForOrder($order);
            $payment = $this->paymentService->createForPackageOrderInvoice($invoice);
            $this->paymentService->markPaid($payment);
            $this->invoiceService->markPaid($invoice);

            try {
                $this->commissionService->accrueForOrder($order->fresh(['items']));
            } catch (\Throwable $e) {
                Log::warning('Order commission accrual failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                if ($order->user_id !== null) {
                    $this->notificationService->createForEventWithEmail([
                        'user_id' => (int) $order->user_id,
                        'event_type' => 'order.paid',
                        'title' => 'Payment Successful',
                        'message' => 'Your order '.$order->order_number.' has been paid successfully.',
                        'subject_type' => 'order',
                        'subject_id' => (string) $order->id,
                        'priority' => 'high',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Order paid notification failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $this->financeService->createEntitlementsForOrder($order->fresh(['items']));
            } catch (\Throwable $e) {
                Log::warning('Entitlement creation failed for order', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $this->voucherService->issueForOrder($order->fresh(['items', 'user']));
            } catch (\Throwable $e) {
                Log::warning('Voucher issuance failed for order', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            return $order->fresh(['items']);
        });
    }

    public function confirmItem(Order $order, string $itemId): OrderItem
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

        return $item->fresh();
    }

    public function failItem(Order $order, string $itemId, string $reason): OrderItem
    {
        $item = $order->items()->whereKey($itemId)->first();
        if ($item === null) {
            throw ValidationException::withMessages([
                'item' => ['Order item not found.'],
            ]);
        }

        $snapshot = $item->service_snapshot ?? [];
        $snapshot['failure_reason'] = $reason;

        $item->status = 'failed';
        $item->service_snapshot = $snapshot;
        $item->save();

        $this->recalculateOrderStatus($order->fresh(['items']));

        return $item->fresh();
    }

    public function cancelOrder(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->assertOrderTransitionAllowed($order, 'cancelled');

            $order->items()->update(['status' => 'cancelled']);

            $order->status = 'cancelled';
            $order->save();

            if ($order->user_id !== null) {
                try {
                    $this->notificationService->createForEventWithEmail([
                        'user_id' => (int) $order->user_id,
                        'event_type' => 'order.cancelled',
                        'title' => 'Order Cancelled',
                        'message' => 'Your order '.$order->order_number.' has been cancelled.',
                        'subject_type' => 'order',
                        'subject_id' => null,
                        'priority' => 'high',
                        'variables' => [
                            'order_number' => (string) ($order->order_number ?? ''),
                        ],
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('order.cancelled notification failed', [
                        'order_id' => $order->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return $order->fresh(['items', 'user']);
        });
    }

    public function listForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->where('metadata->legacy_origin', 'package_order')
            ->where('user_id', $user->id)
            ->with(['items'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function listForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        return Order::query()
            ->where('metadata->legacy_origin', 'package_order')
            ->whereIn('company_id', $companyIds)
            ->with(['user', 'items'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findForUser(int $orderId, User $user): ?Order
    {
        return Order::query()
            ->where('metadata->legacy_origin', 'package_order')
            ->where('metadata->legacy_package_order_id', $orderId)
            ->where('user_id', $user->id)
            ->with(['items'])
            ->first();
    }

    public function findForUserUuid(string $orderId, User $user): ?Order
    {
        return Order::query()
            ->where('metadata->legacy_origin', 'package_order')
            ->whereKey($orderId)
            ->where('user_id', $user->id)
            ->with(['items'])
            ->first();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int $orderId, array $companyIds): ?Order
    {
        return Order::query()
            ->where('metadata->legacy_origin', 'package_order')
            ->where('metadata->legacy_package_order_id', $orderId)
            ->whereIn('company_id', $companyIds)
            ->with(['user', 'items'])
            ->first();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScopeUuid(string $orderId, array $companyIds): ?Order
    {
        return Order::query()
            ->where('metadata->legacy_origin', 'package_order')
            ->whereKey($orderId)
            ->whereIn('company_id', $companyIds)
            ->with(['user', 'items'])
            ->first();
    }

    private function assertOrderTransitionAllowed(Order $order, string $targetStatus): void
    {
        /** @var array<string, list<string>> $allowed */
        $allowed = [
            'cart' => ['pending_payment', 'cancelled'],
            'pending_payment' => ['paid', 'cancelled', 'cart'],
            'paid' => ['confirmed', 'cancelled'],
            'confirmed' => ['cancelled'],
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

    private function recalculateOrderStatus(Order $order): void
    {
        if (! $order->relationLoaded('items')) {
            $order->load('items');
        }

        $items = $order->items;

        $newStatus = $order->status;

        if ($items->isNotEmpty() && $items->every(fn (OrderItem $i): bool => $i->status === 'confirmed')) {
            $newStatus = 'confirmed';
        } elseif ($items->contains(function (OrderItem $i): bool {
            $snapshot = $i->service_snapshot ?? [];

            return (($snapshot['is_required'] ?? false) === true) && $i->status === 'failed';
        })) {
            $newStatus = 'paid';
        } elseif (
            $items->contains(fn (OrderItem $i): bool => $i->status === 'confirmed')
            && $items->every(fn (OrderItem $i): bool => in_array($i->status, ['confirmed', 'pending'], true))
        ) {
            $newStatus = 'paid';
        }

        if ($newStatus !== $order->status) {
            $previousStatus = $order->status;
            $order->status = $newStatus;
            $order->save();

            if ($newStatus === 'confirmed' && $order->user_id !== null) {
                try {
                    $this->notificationService->createForEventWithEmail([
                        'user_id' => (int) $order->user_id,
                        'event_type' => 'order.confirmed',
                        'title' => 'Order Confirmed',
                        'message' => 'Your order '.$order->order_number.' has been fully confirmed.',
                        'subject_type' => 'order',
                        'subject_id' => null,
                        'priority' => 'high',
                        'variables' => [
                            'order_number' => (string) ($order->order_number ?? ''),
                        ],
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('order.confirmed notification failed', [
                        'order_id' => $order->id,
                        'previous_status' => $previousStatus,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function resolveOfferItemId(Offer $offer, string $itemType): ?int
    {
        $relation = match ($itemType) {
            'flight' => 'flight',
            'hotel' => 'hotel',
            'transfer' => 'transfer',
            'car' => 'car',
            'excursion' => 'excursion',
            'visa' => 'visa',
            'package' => 'package',
            default => null,
        };

        if ($relation !== null) {
            $column = $relation.'_id';
            $directId = $offer->{$column} ?? null;
            if (is_numeric($directId)) {
                return (int) $directId;
            }

            if (method_exists($offer, $relation)) {
                $offer->loadMissing($relation);
                $relatedModel = $offer->getRelation($relation);
                if ($relatedModel !== null && isset($relatedModel->id)) {
                    return (int) $relatedModel->id;
                }
            }
        }

        return null;
    }
}
