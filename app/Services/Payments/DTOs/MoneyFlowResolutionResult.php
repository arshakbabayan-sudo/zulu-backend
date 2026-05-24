<?php

declare(strict_types=1);

namespace App\Services\Payments\DTOs;

/**
 * Immutable result of MoneyFlowResolver::resolve().
 *
 * Persisted into order_pricing_snapshots.snapshot_json.money_flow so
 * the booking's money-flow choice is locked at booking time (later
 * money_flow_terms changes don't retroactively alter how an existing
 * order settles).
 */
final class MoneyFlowResolutionResult
{
    /**
     * @param  array<string, mixed>  $snapshotPayload
     */
    public function __construct(
        public readonly string $collectionModel,
        public readonly ?int $remittanceDays,
        public readonly ?string $invoicingPeriod,
        public readonly ?int $termIdApplied,
        public readonly string $scopeMatched,
        public readonly array $snapshotPayload,
    ) {}
}
