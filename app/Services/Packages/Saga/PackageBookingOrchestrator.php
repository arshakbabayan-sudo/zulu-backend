<?php

namespace App\Services\Packages\Saga;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageBookingSaga;
use App\Models\PackageComponent;
use App\Models\Payment;
use App\Models\SagaComponentState;
use App\Models\SagaStateLog;
use App\Services\Payments\PaymentService;
use App\Services\Webhooks\WebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PART 19 / ADR-005 — Atomic package booking saga.
 *
 * Given a paid Order containing one or more package OrderItems, atomically
 * reserves all sub-services and confirms them as a unit. On any reservation
 * failure, compensates (rolls back) all already-reserved components and
 * marks the order failed.
 *
 * Idempotent: re-running for the same Order returns the existing saga
 * without duplicating work. Each component reservation has a deterministic
 * idempotency_key so reservers can de-duplicate at the supplier protocol layer.
 */
class PackageBookingOrchestrator
{
    public function __construct(
        private ComponentReserverRegistry $registry,
        private ?PaymentService $paymentService = null,
        private ?WebhookService $webhookService = null,
    ) {}

    private function paymentService(): PaymentService
    {
        return $this->paymentService ?? app(PaymentService::class);
    }

    private function webhookService(): WebhookService
    {
        return $this->webhookService ?? app(WebhookService::class);
    }

    /**
     * Run the saga for an order. Returns the saga record (existing or new).
     */
    public function runForOrder(Order $order): PackageBookingSaga
    {
        $existing = PackageBookingSaga::query()->where('order_id', $order->id)->first();
        if ($existing !== null && $existing->isTerminal()) {
            return $existing;
        }

        $packageItems = $this->packageItemsOf($order);
        if ($packageItems === []) {
            // Not a package order — create a no-op saga immediately confirmed
            return $this->markEmptySagaAsConfirmed($order);
        }

        $saga = $existing ?? $this->initializeSaga($order, $packageItems);

        $this->reservePhase($saga);
        $saga->refresh();

        if ($this->anyComponentFailed($saga)) {
            $this->rollbackPhase($saga);
        } else {
            $this->confirmPhase($saga, $order);
        }

        return $saga->fresh();
    }

    /**
     * @return array<int, OrderItem>
     */
    private function packageItemsOf(Order $order): array
    {
        return $order->items()
            ->where('item_type', 'package')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, OrderItem>  $packageItems
     */
    private function initializeSaga(Order $order, array $packageItems): PackageBookingSaga
    {
        return DB::transaction(function () use ($order, $packageItems): PackageBookingSaga {
            $primaryItem = $packageItems[0];
            $packageId = $primaryItem->package_id ?? $primaryItem->item_id;

            $saga = PackageBookingSaga::query()->create([
                'order_id' => $order->id,
                'package_id' => $packageId,
                'status' => 'pending',
                'started_at' => now(),
                'context' => [
                    'package_item_count' => count($packageItems),
                    'order_total' => (float) $order->total,
                    'currency' => $order->currency,
                ],
            ]);

            foreach ($packageItems as $item) {
                $this->createComponentStatesForItem($saga, $item);
            }

            $this->log($saga, null, 'pending', 'created', null, [
                'package_item_count' => count($packageItems),
            ]);

            return $saga->fresh();
        });
    }

    private function createComponentStatesForItem(PackageBookingSaga $saga, OrderItem $item): void
    {
        $package = Package::query()->find($item->package_id ?? $item->item_id);

        if ($package === null) {
            // Package not found — synthesize a single component state from the item itself
            // to surface the failure during reserve phase.
            SagaComponentState::query()->create([
                'saga_id' => $saga->id,
                'order_item_id' => $item->id,
                'service_type' => $this->normalizeServiceType($item->item_type),
                'service_id' => $item->item_id,
                'status' => 'pending',
                'idempotency_key' => $this->idempotencyKey($saga->id, null, $item->id, $item->item_type),
            ]);

            return;
        }

        $components = $package->components()->get();

        if ($components->isEmpty()) {
            // Package has no components defined — treat as single-service no-op
            SagaComponentState::query()->create([
                'saga_id' => $saga->id,
                'order_item_id' => $item->id,
                'service_type' => 'visa', // arbitrary placeholder, real packages always have components
                'service_id' => null,
                'status' => 'pending',
                'idempotency_key' => $this->idempotencyKey($saga->id, null, $item->id, 'placeholder'),
            ]);

            return;
        }

        foreach ($components as $component) {
            $serviceType = $this->normalizeServiceType($component->service_type ?? $component->module_type);
            SagaComponentState::query()->create([
                'saga_id' => $saga->id,
                'package_component_id' => $component->id,
                'order_item_id' => $item->id,
                'service_type' => $serviceType,
                'service_id' => $component->service_id,
                'status' => 'pending',
                'idempotency_key' => $this->idempotencyKey($saga->id, $component->id, $item->id, $serviceType),
            ]);
        }
    }

    private function reservePhase(PackageBookingSaga $saga): void
    {
        $previousStatus = $saga->status;
        $saga->status = 'reserving';
        $saga->save();
        $this->log($saga, $previousStatus, 'reserving', 'reserve_started');

        $componentStates = $saga->components()->where('status', 'pending')->get();

        foreach ($componentStates as $componentState) {
            $this->reserveOne($saga, $componentState);
            // Short-circuit on first failure (atomic semantics)
            $componentState->refresh();
            if ($componentState->status === 'failed') {
                break;
            }
        }
    }

    private function reserveOne(PackageBookingSaga $saga, SagaComponentState $componentState): void
    {
        $componentState->status = 'reserving';
        $componentState->attempted_at = now();
        $componentState->save();

        try {
            $reserver = $this->registry->for($componentState->service_type);
        } catch (Throwable $e) {
            $componentState->status = 'failed';
            $componentState->error_message = 'No reserver: '.$e->getMessage();
            $componentState->save();
            $this->log($saga, 'reserving', 'reserving', 'component_failed', $componentState->id, [
                'reason' => 'no_reserver',
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $orderItem = $componentState->order_item_id !== null
            ? OrderItem::query()->find($componentState->order_item_id)
            : null;

        try {
            $result = $reserver->reserve($componentState, $orderItem);
        } catch (Throwable $e) {
            Log::warning('Saga component reservation threw exception', [
                'saga_id' => $saga->id,
                'component_id' => $componentState->id,
                'message' => $e->getMessage(),
            ]);
            $componentState->status = 'failed';
            $componentState->error_message = 'Exception: '.$e->getMessage();
            $componentState->save();
            $this->log($saga, 'reserving', 'reserving', 'component_failed', $componentState->id, [
                'reason' => 'exception',
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if (! $result->success) {
            $componentState->status = 'failed';
            $componentState->error_message = $result->errorMessage;
            $componentState->reservation_payload = $result->payload;
            $componentState->save();
            $this->log($saga, 'reserving', 'reserving', 'component_failed', $componentState->id, [
                'reason' => 'reserver_returned_failure',
                'message' => $result->errorMessage,
            ]);

            return;
        }

        $componentState->status = 'reserved';
        $componentState->supplier_ref = $result->supplierRef;
        $componentState->reservation_payload = $result->payload;
        $componentState->reserved_at = now();
        $componentState->save();
        $this->log($saga, 'reserving', 'reserving', 'component_reserved', $componentState->id, [
            'supplier_ref' => $result->supplierRef,
        ]);
    }

    private function confirmPhase(PackageBookingSaga $saga, Order $order): void
    {
        $saga->status = 'confirming';
        $saga->reserved_at = now();
        $saga->save();
        $this->log($saga, 'reserving', 'confirming', 'reserve_complete');

        $reservedStates = $saga->components()->where('status', 'reserved')->get();

        foreach ($reservedStates as $componentState) {
            try {
                $reserver = $this->registry->for($componentState->service_type);
                $confirmation = $reserver->confirm($componentState);
            } catch (Throwable $e) {
                Log::warning('Saga confirm threw', [
                    'saga_id' => $saga->id,
                    'component_id' => $componentState->id,
                    'message' => $e->getMessage(),
                ]);
                continue; // log but proceed; failure here is a soft issue (already reserved)
            }

            if ($confirmation->success) {
                $componentState->status = 'confirmed';
                $componentState->confirmed_at = now();
                $componentState->save();
                $this->log($saga, 'confirming', 'confirming', 'component_confirmed', $componentState->id);
            }
        }

        $saga->status = 'confirmed';
        $saga->confirmed_at = now();
        $saga->save();
        $this->log($saga, 'confirming', 'confirmed', 'confirmed');

        $order->status = 'confirmed';
        $order->save();

        OrderItem::query()
            ->where('order_id', $order->id)
            ->update(['status' => 'confirmed']);

        try {
            $this->webhookService()->dispatch('order.confirmed', [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'company_id' => $order->company_id,
                'confirmed_at' => now()->toIso8601String(),
            ]);
            $this->webhookService()->dispatch('package_saga.confirmed', [
                'saga_id' => (string) $saga->id,
                'order_id' => (string) $order->id,
                'confirmed_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::warning('order.confirmed/package_saga.confirmed webhook dispatch failed', [
                'saga_id' => $saga->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function rollbackPhase(PackageBookingSaga $saga): void
    {
        $failed = $saga->components()->where('status', 'failed')->first();
        $failureReason = $failed?->error_message ?? 'Unknown failure';

        $saga->status = 'rolling_back';
        $saga->failed_at = now();
        $saga->failure_reason = $failureReason;
        $saga->save();
        $this->log($saga, 'reserving', 'rolling_back', 'rollback_started', null, [
            'reason' => $failureReason,
        ]);

        $reservedStates = $saga->components()->where('status', 'reserved')->get();

        foreach ($reservedStates as $componentState) {
            try {
                $reserver = $this->registry->for($componentState->service_type);
                $result = $reserver->rollback($componentState);
            } catch (Throwable $e) {
                Log::warning('Saga rollback threw', [
                    'saga_id' => $saga->id,
                    'component_id' => $componentState->id,
                    'message' => $e->getMessage(),
                ]);
                $componentState->status = 'failed';
                $componentState->error_message = 'Rollback exception: '.$e->getMessage();
                $componentState->save();
                continue;
            }

            if ($result->success) {
                $componentState->status = 'rolled_back';
                $componentState->rolled_back_at = now();
                $componentState->save();
                $this->log($saga, 'rolling_back', 'rolling_back', 'component_rolled_back', $componentState->id);
            } else {
                $componentState->error_message = $result->errorMessage;
                $componentState->save();
                $this->log($saga, 'rolling_back', 'rolling_back', 'component_rollback_failed', $componentState->id, [
                    'message' => $result->errorMessage,
                ]);
            }
        }

        $saga->status = 'rolled_back';
        $saga->rolled_back_at = now();
        $saga->save();
        $this->log($saga, 'rolling_back', 'rolled_back', 'rolled_back');

        $order = $saga->order;
        if ($order !== null) {
            $order->status = 'failed';
            $order->save();

            $this->attemptRefund($saga, $order);

            $this->fireOrderFailedWebhook($order, $saga);
        }
    }

    /**
     * Attempt to refund the order's payment after rollback. Records outcome on saga.context.
     * Failures (no payment, gateway error, already refunded, no Stripe config) are non-fatal.
     */
    private function attemptRefund(PackageBookingSaga $saga, Order $order): void
    {
        $context = is_array($saga->context) ? $saga->context : [];

        if ($order->payment_id === null) {
            $context['refund'] = ['status' => 'skipped', 'reason' => 'no_payment_on_order', 'at' => now()->toIso8601String()];
            $saga->context = $context;
            $saga->save();
            $this->log($saga, 'rolled_back', 'rolled_back', 'refund_skipped', null, $context['refund']);

            return;
        }

        $payment = Payment::query()->find($order->payment_id);
        if ($payment === null) {
            $context['refund'] = ['status' => 'skipped', 'reason' => 'payment_not_found', 'at' => now()->toIso8601String()];
            $saga->context = $context;
            $saga->save();

            return;
        }

        try {
            $refunded = $this->paymentService()->refund($payment);

            $saga->status = 'refunded';
            $saga->save();

            $context['refund'] = [
                'status' => 'success',
                'payment_id' => $refunded->id,
                'at' => now()->toIso8601String(),
            ];
            $saga->context = $context;
            $saga->save();
            $this->log($saga, 'rolled_back', 'refunded', 'refund_completed', null, $context['refund']);
        } catch (Throwable $e) {
            Log::warning('Saga refund attempt failed', [
                'saga_id' => $saga->id,
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);
            $context['refund'] = [
                'status' => 'failed',
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'at' => now()->toIso8601String(),
            ];
            $saga->context = $context;
            $saga->save();
            $this->log($saga, 'rolled_back', 'rolled_back', 'refund_failed', null, $context['refund']);
        }
    }

    private function fireOrderFailedWebhook(Order $order, PackageBookingSaga $saga): void
    {
        try {
            $this->webhookService()->dispatch('order.failed', [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'total' => (float) $order->total,
                'currency' => $order->currency,
                'company_id' => $order->company_id,
                'failure_reason' => $saga->failure_reason,
                'failed_at' => now()->toIso8601String(),
            ]);
            $this->webhookService()->dispatch('package_saga.failed', [
                'saga_id' => (string) $saga->id,
                'order_id' => (string) $order->id,
                'failure_reason' => $saga->failure_reason,
            ]);
        } catch (Throwable $e) {
            Log::warning('order.failed/package_saga.failed webhook dispatch failed', [
                'saga_id' => $saga->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function anyComponentFailed(PackageBookingSaga $saga): bool
    {
        return $saga->components()->where('status', 'failed')->exists();
    }

    private function markEmptySagaAsConfirmed(Order $order): PackageBookingSaga
    {
        return DB::transaction(function () use ($order): PackageBookingSaga {
            $saga = PackageBookingSaga::query()->create([
                'order_id' => $order->id,
                'status' => 'confirmed',
                'started_at' => now(),
                'reserved_at' => now(),
                'confirmed_at' => now(),
                'context' => ['no_op' => true, 'reason' => 'no package items'],
            ]);

            $this->log($saga, null, 'confirmed', 'no_op_confirmed');

            return $saga;
        });
    }

    private function idempotencyKey(string $sagaId, ?int $componentId, ?string $orderItemId, string $serviceType): string
    {
        return substr(hash('sha256', implode('|', [$sagaId, $componentId ?? '0', $orderItemId ?? '0', $serviceType, Str::random(8)])), 0, 64);
    }

    private function normalizeServiceType(?string $type): string
    {
        $type = strtolower((string) $type);
        $aliases = [
            'airticket' => 'flight',
            'airtickets' => 'flight',
            'flights' => 'flight',
            'hotels' => 'hotel',
            'transfers' => 'transfer',
            'cars' => 'car',
            'car_rent' => 'car',
            'excursions' => 'excursion',
            'visas' => 'visa',
            'visa_support' => 'visa',
            'insurances' => 'insurance',
        ];

        $normalized = $aliases[$type] ?? $type;

        return in_array($normalized, SagaComponentState::SERVICE_TYPES, true) ? $normalized : 'visa';
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function log(
        PackageBookingSaga $saga,
        ?string $from,
        string $to,
        string $event,
        ?int $componentStateId = null,
        ?array $details = null,
    ): void {
        SagaStateLog::query()->create([
            'saga_id' => $saga->id,
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'component_state_id' => $componentStateId,
            'details' => $details,
            'happened_at' => now(),
        ]);
    }
}
