<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 7 — ui_translations EN seeds for three more 0-t() admin
 * pages refactored to use t():
 *
 *   /platform/users
 *   /platform/seller-applications
 *   /platform/rbac
 *
 * HY/RU come from translations:scan --ui after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // /platform/users
            ['admin.users.title', 'Users'],
            ['admin.users.title_long', 'Platform users'],
            ['admin.users.meta_summary', '{total} total · page {current} of {last}'],
            ['admin.users.search_placeholder', 'Search name or email…'],
            ['admin.users.col_id', 'ID'],
            ['admin.users.col_name', 'Name'],
            ['admin.users.col_email', 'Email'],
            ['admin.users.col_status', 'Status'],
            ['admin.users.col_companies', 'Companies'],
            ['admin.users.col_actions', 'Actions'],
            ['admin.users.empty', 'No users found.'],
            ['admin.users.btn_deactivate', 'Deactivate'],
            ['admin.users.confirm_deactivate', 'Deactivate user #{id}?'],
            ['admin.users.err_load', 'Failed to load users'],
            ['admin.users.err_deactivate', 'Deactivate failed'],

            // /platform/seller-applications
            ['admin.seller_applications.title', 'Seller applications'],
            ['admin.seller_applications.filter_status', 'Status filter'],
            ['admin.seller_applications.filter_default_queue', 'Default queue (pending / under review)'],
            ['admin.seller_applications.status_pending', 'pending'],
            ['admin.seller_applications.status_under_review', 'under_review'],
            ['admin.seller_applications.status_approved', 'approved'],
            ['admin.seller_applications.status_rejected', 'rejected'],
            ['admin.seller_applications.col_id', 'ID'],
            ['admin.seller_applications.col_company', 'Company'],
            ['admin.seller_applications.col_service', 'Service'],
            ['admin.seller_applications.col_status', 'Status'],
            ['admin.seller_applications.col_applied', 'Applied'],
            ['admin.seller_applications.col_actions', 'Actions'],
            ['admin.seller_applications.btn_approve', 'Approve'],
            ['admin.seller_applications.btn_reject', 'Reject'],
            ['admin.seller_applications.prompt_optional_notes', 'Optional notes'],
            ['admin.seller_applications.prompt_rejection_reason', 'Rejection reason (required)'],
            ['admin.seller_applications.err_load', 'Failed to load'],
            ['admin.seller_applications.err_approve', 'Approve failed'],
            ['admin.seller_applications.err_reject', 'Reject failed'],
            ['admin.seller_applications.err_reason_required', 'Rejection reason is required by the API.'],

            // /platform/rbac
            ['admin.rbac.title', 'Roles & permissions'],
            ['admin.rbac.subtitle', 'Read-only inventory of the seeded RBAC scheme. Use this for security audits before any fine-grained refactor.'],
            ['admin.rbac.stat_roles', 'Roles'],
            ['admin.rbac.stat_permissions', 'Permissions'],
            ['admin.rbac.stat_memberships', 'Memberships'],
            ['admin.rbac.stat_super_admins', 'Super admins'],
            ['admin.rbac.filter_permissions', 'Filter permissions'],
            ['admin.rbac.filter_placeholder', 'e.g. inventory, payment, voucher'],
            ['admin.rbac.col_role', 'Role'],
            ['admin.rbac.empty', 'No roles configured.'],
            ['admin.rbac.err_load', 'Failed to load'],
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
