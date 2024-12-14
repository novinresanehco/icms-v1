<?php

// config/cms.php
return [
    'security' => [
        'auth' => [
            'session_lifetime' => env('SESSION_LIFETIME', 1440),
            'token_length' => 64,
            'refresh_threshold' => 30,
            'max_attempts' => env('LOGIN_MAX_ATTEMPTS', 5),
            'lockout_minutes' => env('LOGIN_LOCKOUT_MINUTES', 15),
            'password_min_length' => 12,
            'require_special_chars' => true,
            'require_numbers' => true,
            'require_mixed_case' => true
        ],
        'api' => [
            'rate_limit' => env('API_RATE_LIMIT', 60),
            'rate_window' => env('API_RATE_WINDOW', 1),
            'token_expiry' => env('API_TOKEN_EXPIRY', 60)
        ],
        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation_days' => env('ENCRYPTION_KEY_ROTATION', 30)
        ]
    ],
    'content' => [
        'cache' => [
            'ttl' => env('CONTENT_CACHE_TTL', 3600),
            'max_items' => env('CONTENT_CACHE_MAX', 10000)
        ],
        'media' => [
            'max_size' => env('MEDIA_MAX_SIZE', 10240),
            'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'],
            'path' => env('MEDIA_PATH', 'media'),
            'secure_urls' => true
        ],
        'validation' => [
            'title_max' => 200,
            'body_max' => null,
            'category_required' => true
        ]
    ],
    'performance' => [
        'cache' => [
            'driver' => env('CACHE_DRIVER', 'redis'),
            'prefix' => env('CACHE_PREFIX', 'cms:'),
            'default_ttl' => env('CACHE_TTL', 3600)
        ],
        'queue' => [
            'connection' => env('QUEUE_CONNECTION', 'redis'),
            'default' => env('QUEUE_DEFAULT', 'default'),
            'retry_after' => env('QUEUE_RETRY', 90)
        ],
        'logging' => [
            'channel' => env('LOG_CHANNEL', 'stack'),
            'level' => env('LOG_LEVEL', 'warning'),
            'max_files' => env('LOG_MAX_FILES', 14)
        ]
    ],
    'monitoring' => [
        'metrics' => [
            'collect' => env('COLLECT_METRICS', true),
            'retention_days' => env('METRICS_RETENTION', 30)
        ],
        'alerts' => [
            'email' => env('ALERT_EMAIL'),
            'slack_webhook' => env('ALERT_SLACK_WEBHOOK'),
            'threshold' => [
                'error_rate' => env('ALERT_ERROR_RATE', 5),
                'response_time' => env('ALERT_RESPONSE_TIME', 1000)
            ]
        ],
        'health_check' => [
            'enabled' => env('HEALTH_CHECK_ENABLED', true),
            'frequency' => env('HEALTH_CHECK_FREQUENCY', 5)
        ]
    ]
];

// .env.example
return <<<'ENV'
APP_NAME=LaravelCMS
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=warning
LOG_MAX_FILES=14

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel_cms
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailhog
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=null
MAIL_FROM_NAME="${APP_NAME}"

LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_MINUTES=15
API_RATE_LIMIT=60
API_RATE_WINDOW=1
API_TOKEN_EXPIRY=60

CONTENT_CACHE_TTL=3600
CONTENT_CACHE_MAX=10000
MEDIA_MAX_SIZE=10240
MEDIA_PATH=media

COLLECT_METRICS=true
METRICS_RETENTION=30
HEALTH_CHECK_ENABLED=true
HEALTH_CHECK_FREQUENCY=5

ALERT_EMAIL=admin@example.com
ALERT_ERROR_RATE=5
ALERT_RESPONSE_TIME=1000
ENV;

// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('APP_URL', 'http://localhost')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true
];

// config/cache.php modifications
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
];
