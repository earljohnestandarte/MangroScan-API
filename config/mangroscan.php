<?php

return [
    'api_version' => 'v1',

    'web_url' => env('MANGROSCAN_WEB_URL', 'http://localhost:5173'),

    'seed_user_password' => env('MANGROSCAN_SEED_USER_PASSWORD', ''),

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

    'media' => [
        'disk' => env('MEDIA_UPLOAD_DISK', env('FILESYSTEM_DISK', 'local')),
        'upload_url_ttl_minutes' => (int) env('MEDIA_UPLOAD_URL_TTL_MINUTES', 30),
        'max_upload_bytes' => (int) env('MEDIA_MAX_UPLOAD_BYTES', 5_368_709_120),
    ],

    'exports' => [
        'disk' => env('EXPORT_DISK', env('FILESYSTEM_DISK', 'local')),
        'download_url_ttl_minutes' => (int) env('EXPORT_DOWNLOAD_URL_TTL_MINUTES', 10),
    ],
];
