<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the flexible brand_settings JSON into platform_settings.
     *
     * Structure intentionally mixes well-known reserved keys
     * (logo_url, phone, email, social.*) with an open
     * `custom_fields[]` array so admins can add new editable
     * fields without a code change (Sprint 1 Step 1.1).
     *
     * On read: GET /api/brand-settings returns the JSON as-is.
     * On write: PATCH validates only the reserved keys; custom_fields
     * is free-form (key + label + type + value per item).
     */
    public function up(): void
    {
        $now = now();

        $brand = [
            // Reserved: brand imagery
            'logo_url' => '/brand/logo-zulu.svg',
            'emblem_url' => '/brand/zulu-emblem.svg',
            'favicon_url' => '/favicon.svg',

            // Reserved: contact
            'phone' => null,
            'email' => null,
            'address' => null,
            'address_city' => 'Yerevan',
            'address_country' => 'Armenia',

            // Reserved: social network links (null = "no link yet, hide from footer")
            'social_links' => [
                'facebook' => null,
                'instagram' => null,
                'linkedin' => null,
                'tiktok' => null,
                'youtube' => null,
                'telegram' => null,
                'whatsapp' => null,
            ],

            // Open list — admin can add as many extra fields as they want
            // from the /platform/settings/brand UI. Each entry shape:
            //   { "key": "...", "label": "...", "type": "text|url|email|phone|image", "value": "..." }
            'custom_fields' => [],
        ];

        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'brand_settings'],
            [
                'value' => json_encode($brand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
                'description' => 'Brand-wide settings: logo, contact info, social links, and admin-defined custom fields. Read by Header.tsx, Footer.tsx, and the /contact page. Editable from /platform/settings/brand.',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'brand_settings')->delete();
    }
};
