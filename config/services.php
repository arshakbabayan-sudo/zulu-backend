<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    // Phase 8 — social login (Laravel Socialite). Credentials live in .env on
    // the server; never commit secrets. Redirect URIs must match exactly what
    // we registered with each provider (Google Cloud Console / Facebook
    // Developer Platform). The frontend host(s) that may initiate the flow
    // are listed under OAUTH_ALLOWED_FRONTEND_HOSTS (comma-separated, no scheme).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    // Twilio — SMS gateway. Empty by default; presence of `sid` flips
    // SmsProvider binding from NoopSmsProvider to TwilioSmsProvider.
    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
    ],

    // Firebase Cloud Messaging — push gateway. Empty by default;
    // presence of `project_id` flips PushProvider binding from
    // NoopPushProvider to FirebasePushProvider.
    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'server_key' => env('FIREBASE_SERVER_KEY'),
    ],

    'oauth' => [
        // Where to redirect the browser after a successful provider exchange.
        // The OAuthController will issue a Sanctum token and append it to a
        // path on this host. Multiple comma-separated hosts are allowed —
        // the controller picks the one matching the original initiator.
        'allowed_frontend_hosts' => env('OAUTH_ALLOWED_FRONTEND_HOSTS', 'zulu.am,www.zulu.am,zuluspin.com,www.zuluspin.com'),
        'success_path' => env('OAUTH_SUCCESS_PATH', '/auth/oauth-success'),
        'failure_path' => env('OAUTH_FAILURE_PATH', '/login'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Drive (multi-tenant — Phase Է, 2026-05-25)
    |--------------------------------------------------------------------------
    |
    | DELIBERATELY SEPARATE from the `google` block above (which is the
    | Socialite social-login client with scopes openid+profile+email).
    | Drive requires `https://www.googleapis.com/auth/drive.file` plus
    | `access_type=offline` + `prompt=consent` to get a refresh token,
    | which would worsen the login UX if reused for sign-in.
    |
    | Credentials are issued from the zuluspin26@gmail.com Google Cloud
    | project (Authorized redirect URIs MUST list both
    | http://127.0.0.1:8000/api/auth/google-drive/callback (local dev)
    | and https://api.zulu.am/api/auth/google-drive/callback (prod) —
    | Google checks the exact string).
    |
    | `drive.file` (not `drive`) scope means we can only see/edit files
    | our own app created — we cannot read the user's existing Drive
    | contents. This is the right tenant-isolation default.
    */
    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),
        'root_folder_name' => env('GOOGLE_DRIVE_ROOT_FOLDER', 'ZULU_Spin_Files'),
        // After successful Drive connection the controller bounces back
        // to {frontend host}/settings/integrations?google_drive=connected.
        // Reuses oauth.allowed_frontend_hosts for the host allow-list.
        'success_path' => env('GOOGLE_DRIVE_SUCCESS_PATH', '/admin/settings/integrations'),
    ],

];
