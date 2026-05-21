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
    | SPA auth.
    |
    | Phase 2 / ADR-006 (httpOnly cookie auth) — when the frontend storefront +
    | admin migrate off Bearer tokens, this section needs two changes in prod:
    |   - CORS_SUPPORTS_CREDENTIALS=true
    |   - CORS_ALLOWED_ORIGINS=https://www.zulu.am,https://admin.zulu.am,https://zulu.am
    | (wildcard + credentials is rejected by browsers). Until both frontends
    | are wired to cookies, leaving these as today is correct — Sanctum's
    | statefulApi() middleware in bootstrap/app.php is harmless in parallel
    | with Bearer auth.
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
