<?php

declare(strict_types=1);

return [
    'otp' => [
        'length' => (int) env('OTP_LENGTH', 6),
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
    ],
    'sms' => [
        'gateway_url' => env('SMS_GATEWAY_URL', ''),
        'gateway_method' => strtoupper((string) env('SMS_GATEWAY_METHOD', 'POST')),
        'gateway_headers' => array_values(array_filter(array_map('trim', explode('||', (string) env('SMS_GATEWAY_HEADERS', 'Content-Type: application/json'))))),
        'body_template' => env('SMS_GATEWAY_BODY_TEMPLATE', '{"phone":"{{phone}}","message":"{{message}}","otp":"{{otp}}"}'),
        'bridge_enabled' => filter_var(env('SMS_BRIDGE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'bridge_token' => env('SMS_BRIDGE_TOKEN', ''),
        'bridge_batch_limit' => max(1, (int) env('SMS_BRIDGE_BATCH_LIMIT', 25)),
    ],
];
