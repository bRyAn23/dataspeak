<?php

/*
 * Cross-origin requests from the frontend origin only (Amplify domain in production, Vite dev server locally).
 * Authentication is a bearer token, so credentials are not needed; nothing else may call the API from a browser.
 * CORS_ALLOWED_ORIGINS is a comma-separated list; FRONTEND_URL is always included.
 */
$origins = array_values(array_unique(array_filter(array_map('trim', array_merge(
    [rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/')],
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)))));

return [
    'paths' => ['api/*', 'up'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Dataspeak-Language', 'X-Requested-With'],
    'exposed_headers' => ['Content-Disposition', 'Content-Language', 'Idempotent-Replayed'],
    'max_age' => 600,
    'supports_credentials' => false,
];
