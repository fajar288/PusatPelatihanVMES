<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | API dikonsumsi oleh frontend Next.js (FRONTEND_URL, default localhost:3000).
    | Origin dibatasi eksplisit dan credentials diizinkan agar Sanctum SPA
    | (cookie session) maupun token bearer dapat dipakai. Tambahkan origin lain
    | (mis. domain produksi) lewat FRONTEND_URL dipisah koma.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
