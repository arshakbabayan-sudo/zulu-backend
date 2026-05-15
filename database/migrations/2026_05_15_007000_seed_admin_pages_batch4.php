<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 5 — ui_translations EN seeds for the next round
 * of admin page refactors:
 *
 *   /platform/api-docs
 *   /platform/finance-summary
 *   /platform/invoices
 *   /platform/payments
 *   /platform/reviews
 *
 * All five pages had 0 t() calls before this batch. The TSX changes
 * land in the matching zulu-admin-next commit. This migration seeds
 * EN-only; HY/RU come from the AI translator scan that runs after
 * deploy:  php artisan translations:scan --ui
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // /platform/api-docs
            ['admin.api_docs.title', 'API documentation'],
            ['admin.api_docs.subtitle', 'Live OpenAPI 3.0 spec generated nightly from registered routes. Try-it-out works with your current admin token.'],
            ['admin.api_docs.spec_source_prefix', 'Spec source:'],
            ['admin.api_docs.spec_source_suffix', 'on the backend, regenerated daily at 04:00 UTC'],
            ['admin.api_docs.spec_scheduler', 'scheduler'],
            ['admin.api_docs.regenerate_hint', 'To regenerate now from the backend:'],

            // /platform/finance-summary
            ['admin.finance_summary.title', 'Platform finance summary'],
            ['admin.finance_summary.title_short', 'Finance summary'],
            ['admin.finance_summary.btn_refresh', 'Refresh'],
            ['admin.finance_summary.err_load', 'Failed to load finance summary'],
            ['admin.finance_summary.card_total_payments', 'Total payments (paid)'],
            ['admin.finance_summary.card_commission_accrued', 'Commission accrued'],
            ['admin.finance_summary.card_commission_pending', 'Commission pending'],
            ['admin.finance_summary.sub_paid_payments', '{n} paid payment(s)'],
            ['admin.finance_summary.sub_commission_records', '{n} commission record(s) total'],

            // /platform/invoices
            ['admin.invoices.title', 'Invoices'],
            ['admin.invoices.err_load', 'Failed to load invoices'],
            ['admin.invoices.err_generic', 'Action failed'],
            ['admin.invoices.confirm_issue', 'Issue this invoice?'],
            ['admin.invoices.confirm_cancel', 'Cancel this invoice?'],
            ['admin.invoices.empty', 'No invoices found'],
            ['admin.invoices.btn_issue', 'Issue'],
            ['admin.invoices.col_id', 'ID'],
            ['admin.invoices.col_invoice_number', 'Invoice #'],
            ['admin.invoices.col_status', 'Status'],
            ['admin.invoices.col_amount', 'Amount'],
            ['admin.invoices.col_company', 'Company'],
            ['admin.invoices.col_issued', 'Issued'],
            ['admin.invoices.col_due', 'Due'],
            ['admin.invoices.col_actions', 'Actions'],

            // /platform/payments
            ['admin.payments.title', 'Platform payments'],
            ['admin.payments.title_short', 'Payments'],
            ['admin.payments.err_load', 'Failed to load payments'],
            ['admin.payments.placeholder_status', 'e.g. completed'],
            ['admin.payments.col_currency', 'Currency'],
            ['admin.payments.col_method', 'Method'],
            ['admin.payments.col_paid_at', 'Paid at'],
            ['admin.payments.col_invoice', 'Invoice'],

            // /platform/reviews
            ['admin.reviews.title', 'Reviews moderation'],
            ['admin.reviews.title_short', 'Reviews'],
            ['admin.reviews.err_load', 'Failed to load reviews'],
            ['admin.reviews.err_moderation', 'Moderation failed'],
            ['admin.reviews.prompt_notes', 'Optional moderation notes'],
            ['admin.reviews.filter_status', 'Status filter'],
            ['admin.reviews.status_published', 'published'],
            ['admin.reviews.status_hidden', 'hidden'],
            ['admin.reviews.col_rating', 'Rating'],
            ['admin.reviews.col_text', 'Text'],
            ['admin.reviews.col_target', 'Target'],
            ['admin.reviews.col_user', 'User'],
            ['admin.reviews.col_moderate', 'Moderate'],
            ['admin.reviews.btn_set', 'Set {status}'],
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
