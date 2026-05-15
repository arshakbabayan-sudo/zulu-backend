<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 6 — ui_translations EN seeds for next two
 * 0-t() admin pages refactored to use t():
 *
 *   /statistics              (operator statistics, super-admin view)
 *   /platform/package-orders
 *
 * HY/RU come from translations:scan --ui after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // /statistics
            ['admin.operator_statistics.title', 'Operator statistics'],
            ['admin.operator_statistics.err_load', 'Failed to load statistics'],
            ['admin.operator_statistics.placeholder_company_required', 'Required for super-admin scope'],
            ['admin.operator_statistics.context_active_company', 'Using context active company id {id} (server resolves membership).'],

            // /platform/package-orders
            ['admin.package_orders.title', 'Platform package orders'],
            ['admin.package_orders.title_short', 'Package orders'],
            ['admin.package_orders.err_load', 'Failed to load package orders'],
            ['admin.package_orders.err_invalid_company', 'Company ID must be a positive number'],
            ['admin.package_orders.placeholder_status', 'order status'],
            ['admin.package_orders.placeholder_payment_status', 'payment status'],
            ['admin.package_orders.placeholder_optional', 'optional'],
            ['admin.package_orders.filter_payment_status', 'Payment status'],
            ['admin.package_orders.btn_apply_company', 'Apply company'],
            ['admin.package_orders.col_order_number', 'Order #'],
            ['admin.package_orders.col_payment', 'Payment'],
            ['admin.package_orders.col_total', 'Total'],
            ['admin.package_orders.col_package', 'Package'],
            ['admin.package_orders.col_buyer', 'Buyer'],
        ];

        $batch = [];
        foreach ($rows as $r) {
            [$key, $en] = $r;
            $batch[] = ['language_code' => 'en', 'key' => $key, 'value' => $en, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('ui_translations')->upsert(
            $batch,
            ['language_code', 'key'],
            ['value', 'updated_at']
        );

        Cache::forget('ui_translations_en');
    }

    public function down(): void
    {
        // No down() — keys may have been further translated by AI scan.
    }
};
