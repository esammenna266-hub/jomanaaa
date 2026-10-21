<?php

/*
| Which websites may call the API from a browser. The web app normally runs on its own address (for example a Vercel
| domain) while the API runs elsewhere, so that address must be listed. Locally the dev server proxies /api and needs
| nothing here. The API uses bearer tokens, not cookies, so credentials are not shared.
|
| CORS_ALLOWED_ORIGINS="https://gomana-demo.vercel.app,https://app.example.com"
*/

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    // Vercel gives every preview deployment its own address; list only the ones you trust, or set a pattern here.
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
