<?php

namespace App\Core\Config;

return [
    'app' => [
        'name' => env('APP_NAME', 'Secure CMS'),
        'env' => 'production',
        'debug' => false,
        'url' => env('APP_URL'),
        'timezone' => 'UTC',
        'locale' => 'en',
        'key' => env('APP_KEY'),
        'cipher' => 'AES-256-CBC'
    ],

    'security' => [
        'headers' => [
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "upgrade-insecure-requests"
            ]),
        ],
        
        'authentication' => [
            'guards' => [
                'web' => [
                    'driver' => 'session',
                    'provider' => 'users'
                ],
                'api' => [
                    'driver' => 'token',
                    'provider' => 'users',
                    'hash' => true
                ]
            ],
            'providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => App\Models\User::class
                ]
            ],
            'passwords' => [
                'users' => [
                    'provider' => 'users',
                    'table' => 'password_resets',
                    'expire' => 60,
                    'throttle' => 60
                ]
            ],
            'password_timeout' => 10800
        ],

        'session' => [
            'driver' => 'redis',
            'lifetime' => 120,
            'expire_on_close' => true,
            'encrypt' => true,
            'secure' => true,
            'http_only' => true,
            'same_site' => 'lax'
        ]
    ],

    'database' => [
        'default' => 'mysql',
        'connections' => [
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST'),
                'database' => env('DB_DATABASE'),
                'username' => env('DB_USERNAME'),
                'password' => env('DB_PASSWORD'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => 'InnoDB',
                'options' => extension_loaded('pdo_mysql') ? array_filter([
                    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_TIMEOUT => 60,
                    PDO::ATTR_PERSISTENT => false
                ]) : []
            ]
        ]
    ],

    'cache' => [
        'default' => 'redis',
        'stores' => [
            'redis' => [
                'driver' => 'redis',
                'connection' => 'cache',
                'lock_connection' => 'default'
            ]
        ],
        'prefix' => env('CACHE_PREFIX', 'secure_cms_cache'),
        'ttl' => 3600,
        'prevent_replication_lag' => true
    ],

    'monitoring' => [
        'enabled' => true,
        'metrics' => [
            'system_metrics' => true,
            'application_metrics' => true,
            'security_metrics' => true,
            'performance_metrics' => true
        ],
        'thresholds' => [
            'cpu_usage' => 80,
            'memory_usage' => 85,
            'response_time' => 500,
            'error_rate' => 1
        ],
        'alerts' => [
            'channels' => ['slack', 'email'],
            'threshold_alerts' => true,
            'security_alerts' => true,
            'performance_alerts' => true
        ]
    ],

    'logging' => [
        'default' => 'stack',
        'deprecations' => 'null',
        'channels' => [
            'stack' => [
                'driver' => 'stack',
                'channels' => ['daily', 'slack'],
                'ignore_exceptions' => false,
            ],
            'daily' => [
                'driver' => 'daily',
                'path' => storage_path('logs/laravel.log'),
                'level' => env('LOG_LEVEL', 'debug'),
                'days' => 14,
            ],
            'slack' => [
                'driver' => 'slack',
                'url' => env('LOG_SLACK_WEBHOOK_URL'),
                'username' => 'Security Monitor',
                'emoji' => ':boom:',
                'level' => env('LOG_LEVEL', 'critical'),
            ]
        ]
    ]
];
