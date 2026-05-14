<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI provider
    |--------------------------------------------------------------------------
    |
    | Active provider that AISearchService dispatches to. Phase 3 ships with
    | Gemini because the free tier (1,500 req/day, no credit card required)
    | is generous enough for our current scale. Swap to Claude later by
    | flipping AI_PROVIDER + supplying ANTHROPIC_API_KEY.
    |
    | Supported: "gemini", "claude"
    |
    */

    'driver' => env('AI_PROVIDER', 'gemini'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],

];
