<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 / Step C.1 — verifies the 5 new tables migrate cleanly and
 * have the expected columns. The PG-only CHECK constraints are tested
 * separately on prod (sqlite doesn't enforce named CHECKs the same way).
 */
class PhaseOneMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_rules_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('pricing_rules'));
        foreach ([
            'id', 'scope_type', 'operator_id', 'agent_id', 'service_category',
            'destination_id', 'markup_type', 'markup_value', 'min_sell_amount',
            'max_sell_amount', 'currency', 'effective_from', 'effective_until',
            'priority', 'is_active', 'created_by', 'created_at', 'updated_at',
            'deleted_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('pricing_rules', $column),
                "pricing_rules.{$column} should exist"
            );
        }
    }

    public function test_order_pricing_snapshots_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('order_pricing_snapshots'));
        foreach ([
            'id', 'order_id', 'order_item_id', 'offer_id', 'supplier_net',
            'zulu_markup_value', 'zulu_markup_type', 'agent_net',
            'customer_paid', 'agent_margin', 'rule_id_applied', 'currency',
            'snapshot_json', 'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('order_pricing_snapshots', $column),
                "order_pricing_snapshots.{$column} should exist"
            );
        }
    }

    public function test_money_flow_terms_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('money_flow_terms'));
        foreach ([
            'id', 'scope_type', 'operator_id', 'agent_id', 'collection_model',
            'remittance_days', 'invoicing_period', 'is_active',
            'effective_from', 'effective_until', 'created_by',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('money_flow_terms', $column));
        }
    }

    public function test_pricing_audit_log_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('pricing_audit_log'));
        foreach ([
            'id', 'entity_type', 'entity_id', 'action', 'old_values',
            'new_values', 'changed_by', 'reason', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('pricing_audit_log', $column));
        }
    }

    public function test_exchange_rates_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('exchange_rates'));
        foreach ([
            'id', 'source_currency', 'target_currency', 'rate', 'source',
            'fetched_at', 'is_active', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('exchange_rates', $column));
        }
    }
}
