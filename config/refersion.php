<?php

// Refersion API v2026-08 (docs/SPEC.md §3). .env.example points at the local mock instead.
return [
    'base_url' => rtrim((string) env('REFERSION_BASE_URL', 'https://api.refersion.com/v2026-08'), '/'),
    'token_url' => (string) env('REFERSION_TOKEN_URL', 'https://auth.refersion.com/oauth/token'),
    'client_id' => env('REFERSION_CLIENT_ID'),
    'client_secret' => env('REFERSION_CLIENT_SECRET'),

    'page_limit' => (int) env('REFERSION_PAGE_LIMIT', 100),   // the API allows 1..100
    'max_pages' => (int) env('REFERSION_MAX_PAGES', 1000),    // guard against an API that never ends
    'batch_size' => (int) env('REFERSION_BATCH_SIZE', 5),     // concurrent custom-field lookups

    'connect_timeout' => 5,
    'timeout' => 15,
    'retry_attempts' => 3,                                    // backoff 200ms, 400ms
    'max_retry_after' => 10,                                  // a longer Retry-After fails fast
];
