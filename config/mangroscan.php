<?php

return [
    'api_version' => 'v1',

    'web_url' => env('MANGROSCAN_WEB_URL', 'http://localhost:5173'),

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
        'authenticated_requests_per_minute' => (int) env('AUTHENTICATED_REQUESTS_PER_MINUTE', 60),
    ],

    'ai_services' => [
        'connect_timeout_seconds' => (int) env('AI_SERVICE_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout_seconds' => (int) env('AI_SERVICE_TIMEOUT_SECONDS', 10),
    ],
];
