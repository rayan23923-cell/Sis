<?php

/*
 * Online payment of tuition through Iraqi gateways. Each gateway is off until its merchant credentials are set.
 * Credentials live in the environment only, never in the database or the repository.
 *
 * The sandbox gateway is a simulated payment page inside the system, for demonstrations and tests. It is never
 * offered when APP_ENV is production unless PAYMENTS_SANDBOX is set on purpose (staging).
 */
return [
    // Minutes a started payment stays open before it is checked one last time and marked expired.
    'ttl' => (int) env('PAYMENTS_TTL', 30),

    // The smallest amount sent to a gateway, in dinars.
    'minimum' => (int) env('PAYMENTS_MINIMUM', 1000),

    'gateways' => [
        'qicard' => [
            'label' => 'كي كارد',
            'base_url' => env('QICARD_BASE_URL', 'https://uat-sandbox-3ds-api.qi.iq/api/v1'),
            'username' => env('QICARD_USERNAME'),
            'password' => env('QICARD_PASSWORD'),
            'terminal_id' => env('QICARD_TERMINAL_ID'),
        ],
        'zaincash' => [
            'label' => 'زين كاش',
            'base_url' => env('ZAINCASH_BASE_URL', 'https://pg-api-uat.zaincash.iq'),
            'client_id' => env('ZAINCASH_CLIENT_ID'),
            'client_secret' => env('ZAINCASH_CLIENT_SECRET'),
        ],
        'fib' => [
            'label' => 'مصرف العراق الأول FIB',
            'base_url' => env('FIB_BASE_URL', 'https://fib.stage.fib.iq'),
            'client_id' => env('FIB_CLIENT_ID'),
            'client_secret' => env('FIB_CLIENT_SECRET'),
        ],
        'sandbox' => [
            'label' => 'دفع تجريبي',
            'enabled' => (bool) env('PAYMENTS_SANDBOX', false),
        ],
    ],
];
