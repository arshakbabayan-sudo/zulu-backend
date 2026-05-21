<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Separated browsers (Next.js admin, Next.js storefront) call `/api/*` with
    | `Authorization: Bearer <token>`. That is not a CORS “credential” (cookies);
    | keep `supports_credentials` false unless you intentionally adopt cookie-based
    | SPA auth (not the Phase 2 contract).
    |
    | `CORS_ALLOWED_ORIGINS`: comma-separated exact origins, e.g.
    | https://admin.example.com,https://shop.example.com
    | Use `*` only for local/dev; production should list explicit origins.
    |
    | Native mobile apps are not subject to browser CORS; they use the same Bearer
    | API without this file affecting them.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => value(function (): array {
        $raw = env('CORS_ALLOWED_ORIGINS');

        if ($raw === null || $raw === '' || trim((string) $raw) === '*') {
            return ['*'];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
