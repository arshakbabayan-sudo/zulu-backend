<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 9 — ui_translations EN seeds for two more 0-t() admin
 * pages refactored to use t():
 *
 *   /platform/packages  (governance view)
 *   /localization/templates
 *
 * HY/RU come from translations:scan --ui after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // /platform/packages
            ['admin.packages.title', 'Packages'],
            ['admin.packages.title_long', 'Packages governance'],
            ['admin.packages.filter_status', 'Status'],
            ['admin.packages.filter_company_id', 'Company ID'],
            ['admin.packages.placeholder_status', 'package status'],
            ['admin.packages.placeholder_optional', 'optional'],
            ['admin.packages.btn_apply_company', 'Apply company'],
            ['admin.packages.btn_homepage_feature', 'Homepage feature'],
            ['admin.packages.btn_force_deactivate', 'Force deactivate'],
            ['admin.packages.col_id', 'ID'],
            ['admin.packages.col_title', 'Title'],
            ['admin.packages.col_type', 'Type'],
            ['admin.packages.col_status', 'Status'],
            ['admin.packages.col_company', 'Company'],
            ['admin.packages.col_public_bookable', 'Public / bookable'],
            ['admin.packages.col_actions', 'Actions'],
            ['admin.packages.yes', 'yes'],
            ['admin.packages.no', 'no'],
            ['admin.packages.prompt_deactivate_reason', 'Optional reason for force-deactivating "{title}" (package #{id})'],
            ['admin.packages.err_load', 'Failed to load packages'],
            ['admin.packages.err_invalid_company', 'Company ID must be a positive number'],
            ['admin.packages.err_deactivate', 'Deactivate failed'],

            // /localization/templates
            ['admin.localization_templates.title', 'Notification templates'],
            ['admin.localization_templates.field_event', 'Event'],
            ['admin.localization_templates.field_language', 'Language'],
            ['admin.localization_templates.field_channel', 'Channel'],
            ['admin.localization_templates.field_title_template', 'Title template'],
            ['admin.localization_templates.field_body_template', 'Body template'],
            ['admin.localization_templates.field_active', 'Active'],
            ['admin.localization_templates.btn_load', 'Load'],
            ['admin.localization_templates.btn_save', 'Save'],
            ['admin.localization_templates.msg_loaded', 'Loaded.'],
            ['admin.localization_templates.msg_not_found', 'No template found - fill below and save to create.'],
            ['admin.localization_templates.msg_saved', 'Saved.'],
            ['admin.localization_templates.err_load', 'Load failed'],
            ['admin.localization_templates.err_save', 'Save failed'],
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
