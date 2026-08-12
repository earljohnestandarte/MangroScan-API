<?php

return [
    'api_version' => 'v1',

    'features' => [
        'health_checks' => true,
        'request_ids' => true,
        'token_authentication' => true,
    ],

    'limits' => [
        'pagination_per_page_max' => 100,
    ],

    'auth' => [
        'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 60),
        'login_attempts_per_minute' => (int) env('AUTH_LOGIN_ATTEMPTS_PER_MINUTE', 5),
    ],
];
