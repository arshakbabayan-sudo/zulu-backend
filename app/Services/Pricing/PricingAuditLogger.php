<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\MoneyFlowTerm;
use App\Models\PricingAuditLog;
use App\Models\PricingRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 1 / Step D.1 + E — every mutation to pricing_rules or
 * money_flow_terms goes through this logger so the audit trail in
 * pricing_audit_log is complete.
 *
 * Usage:
 *   $auditor->logChange($model, action, actor, reason);
 *   $auditor->logCreate($model, actor, reason);
 *   $auditor->logDelete($model, actor, reason);
 */
class PricingAuditLogger
{
    /**
     * Log a mutation. `$model->getOriginal()` is captured BEFORE save —
     * caller must pass the pre-save snapshot via old_values OR call this
     * BEFORE refreshing the model.
     */
    public function logUpdate(Model $model, array $oldValues, ?User $actor, ?string $reason = null): PricingAuditLog
    {
        return $this->writeRow(
            $model,
            PricingAuditLog::ACTION_UPDATED,
            $oldValues,
            $this->snapshotValues($model),
            $actor,
            $reason
        );
    }

    public function logCreate(Model $model, ?User $actor, ?string $reason = null): PricingAuditLog
    {
        return $this->writeRow(
            $model,
            PricingAuditLog::ACTION_CREATED,
            null,
            $this->snapshotValues($model),
            $actor,
            $reason
        );
    }

    public function logDelete(Model $model, ?User $actor, ?string $reason = null): PricingAuditLog
    {
        return $this->writeRow(
            $model,
            PricingAuditLog::ACTION_DELETED,
            $this->snapshotValues($model),
            null,
            $actor,
            $reason
        );
    }

    public function logDeactivate(Model $model, ?User $actor, ?string $reason = null): PricingAuditLog
    {
        return $this->writeRow(
            $model,
            PricingAuditLog::ACTION_DEACTIVATED,
            ['is_active' => true],
            ['is_active' => false],
            $actor,
            $reason
        );
    }

    public function logReactivate(Model $model, ?User $actor, ?string $reason = null): PricingAuditLog
    {
        return $this->writeRow(
            $model,
            PricingAuditLog::ACTION_REACTIVATED,
            ['is_active' => false],
            ['is_active' => true],
            $actor,
            $reason
        );
    }

    private function writeRow(
        Model $model,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?User $actor,
        ?string $reason,
    ): PricingAuditLog {
        return PricingAuditLog::create([
            'entity_type' => $this->entityType($model),
            'entity_id' => (string) $model->getKey(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_by' => $actor?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function entityType(Model $model): string
    {
        return match (true) {
            $model instanceof PricingRule => PricingAuditLog::ENTITY_PRICING_RULE,
            $model instanceof MoneyFlowTerm => PricingAuditLog::ENTITY_MONEY_FLOW_TERM,
            default => throw new \InvalidArgumentException(
                'PricingAuditLogger only handles PricingRule / MoneyFlowTerm. Got: '.$model::class
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotValues(Model $model): array
    {
        // Only persist the fillable attributes — skip timestamps/internal
        // bookkeeping that aren't part of the business semantics.
        $fillable = $model->getFillable();

        return collect($model->getAttributes())
            ->only($fillable)
            ->map(function ($v) {
                if ($v instanceof \DateTimeInterface) {
                    return $v->format('c');
                }

                return $v;
            })
            ->all();
    }
}
