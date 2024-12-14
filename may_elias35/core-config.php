<?php

// config/security.php
return [
    'auth' => [
        'max_attempts' => 5,
        'decay_minutes' => 30,
        'token_lifetime' => 3600,
        'refresh_lifetime' => 86400,
        'password_min_length' => 12,
        'require_special_chars' => true,
    ],

    'encryption' => [
        'cipher' => 'AES-256-GCM',
        'key_rotation_days' => 30,
    ],

    'security_headers' => [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'self'",
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ],

    'file_uploads' => [
        'max_size' => 10240,
        'allowed_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
        ],
        'scan_uploads' => true,
    ],
];

// config/cache.php
return [
    'default' => env('CACHE_DRIVER', 'redis'),
    
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'cms'),
    'ttl' => env('CACHE_TTL', 3600),
];

// config/logging.php
return [
    'default' => env('LOG_CHANNEL', 'stack'),
    
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily', 'slack'],
        ],
        
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/cms.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],
        
        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'notice',
            'days' => 90,
        ],
    ],
];

// config/cms.php
return [
    'content' => [
        'cache_ttl' => 3600,
        'per_page' => 20,
        'max_title_length' => 255,
        'allowed_statuses' => [
            'draft',
            'published',
            'archived'
        ],
    ],

    'media' => [
        'storage_path' => env('MEDIA_PATH', 'media'),
        'cache_ttl' => 86400,
        'image_sizes' => [
            'thumb' => [150, 150],
            'medium' => [300, 300],
            'large' => [800, 800],
        ],
    ],

    'templates' => [
        'theme_path' => resource_path('themes'),
        'cache_ttl' => 3600,
        'compile_check' => env('APP_DEBUG', false),
    ],

    'monitoring' => [
        'performance' => [
            'slow_query_threshold' => 50,
            'memory_limit' => 128 * 1024 * 1024,
            'cpu_threshold' => 70,
        ],
        'security' => [
            'log_level' => 'notice',
            'alert_threshold' => 'warning',
            'notify_channels' => ['slack', 'email'],
        ],
    ],
];

// services/cms.php
return [
    'security' => [
        'encryption_key' => env('APP_KEY'),
        'jwt_secret' => env('JWT_SECRET'),
        'api_key' => env('CMS_API_KEY'),
    ],
    
    'storage' => [
        'media_url' => env('MEDIA_URL'),
        'cdn_url' => env('CDN_URL'),
    ],

    'cache' => [
        'redis_host' => env('REDIS_HOST', '127.0.0.1'),
        'redis_port' => env('REDIS_PORT', 6379),
    ],

    'monitoring' => [
        'slack_webhook' => env('SLACK_WEBHOOK_URL'),
        'email_alerts' => env('ALERT_EMAIL'),
    ],
];
