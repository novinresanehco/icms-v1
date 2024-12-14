<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'security' => [
        'password_requirements' => [
            'min_length' => 12,
            'require_numbers' => true,
            'require_symbols' => true,
            'require_mixed_case' => true,
            'prevent_common' => true,
        ],
        'session' => [
            'secure' => true,
            'http_only' => true,
            'same_site' => 'lax',
            'lifetime' => 120,
        ],
        'token' => [
            'lifetime' => 3600,
            'refresh_lifetime' => 86400,
            'rotation' => true,
            'blacklist_enabled' => true,
            'blacklist_grace_period' => 30,
        ],
        'rate_limiting' => [
            'enabled' => true,
            'max_attempts' => 5,
            'decay_minutes' => 15,
            'lockout_time' => 900,
        ],
        'mfa' => [
            'enabled' => true,
            'providers' => ['google', 'email'],
            'remember_device' => true,
            'remember_duration' => 30,
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
            'password_history' => 5,
        ],
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'token',
            'provider' => 'users',
            'hash' => true,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'validation' => [
        'password_timeout' => 10800,
        'verify_email' => true,
        'verify_phone' => false,
    ],

    'logging' => [
        'enabled' => true,
        'level' => 'info',
        'events' => [
            'login',
            'logout',
            'failed_login',
            'password_reset',
            'token_refresh',
            'mfa_challenge',
        ],
    ],

    'notifications' => [
        'password_reset' => [
            'email' => true,
            'sms' => false,
            'expires_in' => 3600,
        ],
        'security_alert' => [
            'email' => true,
            'sms' => true,
            'events' => [
                'unusual_login',
                'password_changed',
                'mfa_disabled',
            ],
        ],
    ],
];
