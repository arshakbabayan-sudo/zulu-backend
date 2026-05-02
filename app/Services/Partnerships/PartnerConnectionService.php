<?php

namespace App\Services\Partnerships;

use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Webhooks\WebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * PART 18 — Seller↔Seller B2B partnership lifecycle.
 *
 * Distinct from App\Services\Connections\ConnectionService, which manages
 * service-to-service links (flight↔hotel etc.). This service operates on
 * App\Models\Connection (the partner_connections concept).
 */
class PartnerConnectionService
{
    public function __construct(
        private ?AuditService $auditService = null,
        private ?WebhookService $webhookService = null,
    ) {}

    private function audit(): AuditService
    {
        return $this->auditService ?? app(AuditService::class);
    }

    private function webhook(): WebhookService
    {
        return $this->webhookService ?? app(WebhookService::class);
    }

    private function fireConnectionWebhook(string $event, Connection $connection, array $extra = []): void
    {
        try {
            $this->webhook()->dispatch($event, array_merge([
                'connection_id' => (string) $connection->id,
                'type' => $connection->type,
                'direction' => $connection->direction,
                'status' => $connection->status,
                'seller_a_company_id' => $connection->seller_a_company_id,
                'seller_b_company_id' => $connection->seller_b_company_id,
            ], $extra));
        } catch (\Throwable $e) {
            Log::warning('Connection webhook dispatch failed', [
                'event' => $event,
                'connection_id' => $connection->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Propose a new connection from Seller A to Seller B.
     *
     * @param  array<string, mixed>  $payload  type, direction, share_scope, territorial_scope,
     *                                         display_options, effective_from, effective_to,
     *                                         partner_agreement_id, commission_override_rule_id
     */
    public function propose(
        Company $sellerA,
        Company $sellerB,
        User $proposer,
        array $payload = [],
    ): Connection {
        if ($sellerA->id === $sellerB->id) {
            throw new InvalidArgumentException('A seller cannot connect to itself.');
        }

        $type = $payload['type'] ?? 'supplier_reseller';
        if (! in_array($type, Connection::TYPES, true)) {
            throw new InvalidArgumentException("Invalid connection type: {$type}");
        }

        $direction = $payload['direction'] ?? 'a_to_b';
        if (! in_array($direction, Connection::DIRECTIONS, true)) {
            throw new InvalidArgumentException("Invalid direction: {$direction}");
        }

        $shareScope = $this->normalizeShareScope($payload['share_scope'] ?? null);
        $displayOptions = $this->normalizeDisplayOptions($payload['display_options'] ?? null);

        $duplicate = Connection::query()
            ->where(function ($q) use ($sellerA, $sellerB): void {
                $q->where(function ($qq) use ($sellerA, $sellerB): void {
                    $qq->where('seller_a_company_id', $sellerA->id)
                        ->where('seller_b_company_id', $sellerB->id);
                })->orWhere(function ($qq) use ($sellerA, $sellerB): void {
                    $qq->where('seller_a_company_id', $sellerB->id)
                        ->where('seller_b_company_id', $sellerA->id);
                });
            })
            ->whereIn('status', ['proposed', 'active', 'paused'])
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                "An open connection already exists between these sellers (id={$duplicate->id}, status={$duplicate->status})."
            );
        }

        return DB::transaction(function () use (
            $sellerA,
            $sellerB,
            $proposer,
            $type,
            $direction,
            $shareScope,
            $displayOptions,
            $payload,
        ): Connection {
            $connection = Connection::query()->create([
                'type' => $type,
                'seller_a_company_id' => $sellerA->id,
                'seller_b_company_id' => $sellerB->id,
                'direction' => $direction,
                'partner_agreement_id' => $payload['partner_agreement_id'] ?? null,
                'commission_override_rule_id' => $payload['commission_override_rule_id'] ?? null,
                'share_scope' => $shareScope,
                'territorial_scope' => $payload['territorial_scope'] ?? null,
                'display_options' => $displayOptions,
                'effective_from' => $payload['effective_from'] ?? now(),
                'effective_to' => $payload['effective_to'] ?? null,
                'status' => 'proposed',
                'proposed_at' => now(),
                'proposed_by_user_id' => $proposer->id,
            ]);

            $this->audit()->log([
                'category' => 'connection',
                'actor' => $proposer,
                'subject_type' => 'Connection',
                'subject_id' => (string) $connection->id,
                'action' => 'proposed',
                'changes' => null,
                'context' => [
                    'type' => $type,
                    'direction' => $direction,
                    'seller_a_company_id' => $sellerA->id,
                    'seller_b_company_id' => $sellerB->id,
                ],
            ]);

            $fresh = $connection->fresh();
            $this->fireConnectionWebhook('connection.proposed', $fresh);

            return $fresh;
        });
    }

    public function accept(Connection $connection, User $accepter): Connection
    {
        $this->assertStatus($connection, ['proposed'], 'accept');

        $connection->status = 'active';
        $connection->accepted_at = now();
        $connection->responded_by_user_id = $accepter->id;
        $connection->save();

        $this->audit()->log([
            'category' => 'connection',
            'actor' => $accepter,
            'subject_type' => 'Connection',
            'subject_id' => (string) $connection->id,
            'action' => 'accepted',
            'changes' => ['status' => ['from' => 'proposed', 'to' => 'active']],
            'context' => null,
        ]);

        $fresh = $connection->fresh();
        $this->fireConnectionWebhook('connection.accepted', $fresh);

        return $fresh;
    }

    public function reject(Connection $connection, User $rejecter, ?string $reason = null): Connection
    {
        $this->assertStatus($connection, ['proposed'], 'reject');

        $connection->status = 'rejected';
        $connection->rejection_reason = $reason;
        $connection->responded_by_user_id = $rejecter->id;
        $connection->save();

        $this->audit()->log([
            'category' => 'connection',
            'actor' => $rejecter,
            'subject_type' => 'Connection',
            'subject_id' => (string) $connection->id,
            'action' => 'rejected',
            'changes' => ['status' => ['from' => 'proposed', 'to' => 'rejected']],
            'context' => ['reason' => $reason],
        ]);

        return $connection->fresh();
    }

    /**
     * Counter-offer: counterparty modifies terms; status stays 'proposed', proposer/responder swap.
     *
     * @param  array<string, mixed>  $payload
     */
    public function counter(Connection $connection, User $counterer, array $payload = []): Connection
    {
        $this->assertStatus($connection, ['proposed'], 'counter');

        $changed = [];

        foreach (['type', 'direction', 'partner_agreement_id', 'commission_override_rule_id', 'effective_from', 'effective_to'] as $field) {
            if (array_key_exists($field, $payload)) {
                $changed[$field] = ['from' => $connection->{$field}, 'to' => $payload[$field]];
                $connection->{$field} = $payload[$field];
            }
        }

        if (array_key_exists('share_scope', $payload)) {
            $newScope = $this->normalizeShareScope($payload['share_scope']);
            $changed['share_scope'] = ['from' => $connection->share_scope, 'to' => $newScope];
            $connection->share_scope = $newScope;
        }

        if (array_key_exists('territorial_scope', $payload)) {
            $changed['territorial_scope'] = ['from' => $connection->territorial_scope, 'to' => $payload['territorial_scope']];
            $connection->territorial_scope = $payload['territorial_scope'];
        }

        if (array_key_exists('display_options', $payload)) {
            $newOpts = $this->normalizeDisplayOptions($payload['display_options']);
            $changed['display_options'] = ['from' => $connection->display_options, 'to' => $newOpts];
            $connection->display_options = $newOpts;
        }

        $previousProposerId = $connection->proposed_by_user_id;
        $connection->proposed_by_user_id = $counterer->id;
        $connection->responded_by_user_id = $previousProposerId;
        $connection->proposed_at = now();
        $connection->save();

        $this->audit()->log([
            'category' => 'connection',
            'actor' => $counterer,
            'subject_type' => 'Connection',
            'subject_id' => (string) $connection->id,
            'action' => 'counter_offered',
            'changes' => $changed,
            'context' => null,
        ]);

        return $connection->fresh();
    }

    public function pause(Connection $connection, User $user): Connection
    {
        $this->assertStatus($connection, ['active'], 'pause');

        $connection->status = 'paused';
        $connection->paused_at = now();
        $connection->save();

        $this->audit()->log([
            'category' => 'connection',
            'actor' => $user,
            'subject_type' => 'Connection',
            'subject_id' => (string) $connection->id,
            'action' => 'paused',
            'changes' => ['status' => ['from' => 'active', 'to' => 'paused']],
            'context' => null,
        ]);

        return $connection->fresh();
    }

    public function resume(Connection $connection, User $user): Connection
    {
        $this->assertStatus($connection, ['paused'], 'resume');

        $connection->status = 'active';
        $connection->paused_at = null;
        $connection->save();

        $this->audit()->log([
            'category' => 'connection',
            'actor' => $user,
            'subject_type' => 'Connection',
            'subject_id' => (string) $connection->id,
            'action' => 'resumed',
            'changes' => ['status' => ['from' => 'paused', 'to' => 'active']],
            'context' => null,
        ]);

        return $connection->fresh();
    }

    public function terminate(Connection $connection, User $user, string $reason): Connection
    {
        $this->assertStatus($connection, ['active', 'paused'], 'terminate');

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Termination reason is required.');
        }

        $previous = $connection->status;
        $connection->status = 'terminated';
        $connection->terminated_at = now();
        $connection->termination_reason = $reason;
        $connection->save();

        $this->audit()->log([
            'category' => 'connection',
            'actor' => $user,
            'subject_type' => 'Connection',
            'subject_id' => (string) $connection->id,
            'action' => 'terminated',
            'changes' => ['status' => ['from' => $previous, 'to' => 'terminated']],
            'context' => ['reason' => $reason],
        ]);

        $fresh = $connection->fresh();
        $this->fireConnectionWebhook('connection.terminated', $fresh, ['reason' => $reason]);

        return $fresh;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertStatus(Connection $connection, array $allowed, string $action): void
    {
        if (! in_array($connection->status, $allowed, true)) {
            throw new RuntimeException(
                "Cannot {$action} a connection in status '{$connection->status}'. Allowed: ".implode(', ', $allowed)
            );
        }
    }

    /**
     * @param  mixed  $raw
     * @return array{type: string, list: array<int, mixed>}
     */
    private function normalizeShareScope($raw): array
    {
        if (! is_array($raw)) {
            return ['type' => 'all', 'list' => []];
        }

        $type = $raw['type'] ?? 'all';
        if (! in_array($type, Connection::SHARE_SCOPE_TYPES, true)) {
            $type = 'all';
        }

        $list = is_array($raw['list'] ?? null) ? array_values($raw['list']) : [];

        return ['type' => $type, 'list' => $list];
    }

    /**
     * @param  mixed  $raw
     * @return array{show_supplier_name: bool, white_label: bool, show_to_price: bool, show_rr_price: bool}
     */
    private function normalizeDisplayOptions($raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [
            'show_supplier_name' => (bool) ($raw['show_supplier_name'] ?? true),
            'white_label' => (bool) ($raw['white_label'] ?? false),
            'show_to_price' => (bool) ($raw['show_to_price'] ?? true),
            'show_rr_price' => (bool) ($raw['show_rr_price'] ?? false),
        ];
    }
}
