<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 8 — ui_translations EN seeds for three more 0-t() admin
 * pages refactored to use t():
 *
 *   /platform/newsletter
 *   /platform/security
 *   /platform/banners
 *
 * HY/RU come from translations:scan --ui after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // /platform/newsletter
            ['admin.newsletter.title', 'Newsletter'],
            ['admin.newsletter.title_long', 'Newsletter subscribers'],
            ['admin.newsletter.stat_active', 'Active subscribers'],
            ['admin.newsletter.stat_by_lang', 'By language'],
            ['admin.newsletter.stat_by_source', 'By source'],
            ['admin.newsletter.filter_source', 'Source'],
            ['admin.newsletter.filter_lang', 'Lang'],
            ['admin.newsletter.filter_search', 'Search email'],
            ['admin.newsletter.filter_active_only', 'Active only'],
            ['admin.newsletter.search_placeholder', 'email contains…'],
            ['admin.newsletter.btn_apply', 'Apply'],
            ['admin.newsletter.btn_export_csv', 'Export CSV'],
            ['admin.newsletter.btn_unsubscribe', 'Unsubscribe'],
            ['admin.newsletter.col_id', 'ID'],
            ['admin.newsletter.col_email', 'Email'],
            ['admin.newsletter.col_lang', 'Lang'],
            ['admin.newsletter.col_source', 'Source'],
            ['admin.newsletter.col_subscribed', 'Subscribed'],
            ['admin.newsletter.col_unsubscribed', 'Unsubscribed'],
            ['admin.newsletter.col_actions', 'Actions'],
            ['admin.newsletter.empty', 'No subscribers match'],
            ['admin.newsletter.status_active', 'active'],
            ['admin.newsletter.confirm_unsubscribe', 'Mark this subscriber as unsubscribed?'],
            ['admin.newsletter.err_load', 'Failed to load'],
            ['admin.newsletter.err_unsubscribe', 'Failed'],
            ['admin.newsletter.err_export', 'Export failed'],

            // /platform/security
            ['admin.security.title', 'Security'],
            ['admin.security.subtitle', 'Two-factor authentication coverage and incident-response actions.'],
            ['admin.security.stat_total_users', 'Total users'],
            ['admin.security.stat_2fa_enabled', '2FA enabled'],
            ['admin.security.stat_2fa_pending', '2FA pending'],
            ['admin.security.stat_coverage', 'Coverage'],
            ['admin.security.incident_title', 'Incident response'],
            ['admin.security.incident_help', 'Force-logout works on any user (not just those with 2FA). Use after a credential leak.'],
            ['admin.security.placeholder_user_id', 'User ID'],
            ['admin.security.btn_force_logout', 'Force-logout'],
            ['admin.security.btn_disable_2fa', 'Disable 2FA'],
            ['admin.security.btn_apply', 'Apply'],
            ['admin.security.btn_prev', 'Prev'],
            ['admin.security.btn_next', 'Next'],
            ['admin.security.search_label', 'Search 2FA users (name or email)'],
            ['admin.security.col_user', 'User'],
            ['admin.security.col_role', 'Role'],
            ['admin.security.col_confirmed_at', 'Confirmed at'],
            ['admin.security.col_last_verified', 'Last verified'],
            ['admin.security.col_actions', 'Actions'],
            ['admin.security.empty', 'No 2FA-enabled users found.'],
            ['admin.security.role_super_admin', 'super admin'],
            ['admin.security.never', 'Never'],
            ['admin.security.confirm_force_disable_2fa', 'Force-disable 2FA for {target}? They will be able to log in with password only until they re-enroll.'],
            ['admin.security.confirm_force_logout', "Force-logout {target}? All sanctum tokens will be revoked and they'll need to re-authenticate."],
            ['admin.security.success_2fa_disabled', '2FA disabled for user #{id}'],
            ['admin.security.success_force_logout', 'Force-logout: {count} token(s) revoked for user #{id}'],
            ['admin.security.err_load', 'Failed to load'],
            ['admin.security.err_force_disable', 'Force-disable failed'],
            ['admin.security.err_force_logout', 'Force-logout failed'],
            ['admin.security.err_invalid_user_id', 'Enter a valid user ID'],
            ['admin.security.pagination_summary', 'Page {current} of {last} ({total} entries)'],

            // /platform/banners
            ['admin.banners.title', 'Banners'],
            ['admin.banners.title_long', 'Banner CMS'],
            ['admin.banners.subtitle', 'GET|POST|PATCH|DELETE /api/platform-admin/banners* | multipart image on create / optional on update'],
            ['admin.banners.create_title', 'Create banner'],
            ['admin.banners.edit_title', 'Edit banner #{id}'],
            ['admin.banners.field_image_required', 'Image (required)'],
            ['admin.banners.field_new_image_optional', 'New image (optional)'],
            ['admin.banners.field_title_en', 'title_en'],
            ['admin.banners.field_title_ru', 'title_ru'],
            ['admin.banners.field_title_hy', 'title_hy'],
            ['admin.banners.field_link', 'link_url'],
            ['admin.banners.field_sort', 'sort_order'],
            ['admin.banners.btn_create', 'Create'],
            ['admin.banners.btn_save', 'Save'],
            ['admin.banners.btn_edit', 'Edit'],
            ['admin.banners.btn_delete', 'Delete'],
            ['admin.banners.col_id', 'ID'],
            ['admin.banners.col_preview', 'Preview'],
            ['admin.banners.col_titles', 'Titles'],
            ['admin.banners.col_link', 'Link'],
            ['admin.banners.col_sort', 'Sort'],
            ['admin.banners.col_active', 'Active'],
            ['admin.banners.col_actions', 'Actions'],
            ['admin.banners.yes', 'yes'],
            ['admin.banners.no', 'no'],
            ['admin.banners.confirm_delete', 'Delete this banner?'],
            ['admin.banners.err_load', 'Failed to load banners'],
            ['admin.banners.err_image_required', 'Image file is required.'],
            ['admin.banners.err_create', 'Create failed'],
            ['admin.banners.err_update', 'Update failed'],
            ['admin.banners.err_delete', 'Delete failed'],
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
