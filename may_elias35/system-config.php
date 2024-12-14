<?php

return [
    'security' => [
        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation' => 86400, // 24 hours
            'cipher_suites' => ['TLS_AES_256_GCM_SHA384'],
        ],
        
        'authentication' => [
            'multi_factor' => true,
            'session_lifetime' => 900, // 15 minutes
            'max_attempts' => 3,
            'lockout_time' => 900,
            'token_length' => 64,
        ],
        
        'validation' => [
            'strict_mode' => true,
            'sanitize_input' => true,
            'validate_utf8' => true,
            'allowed_tags' => [],
        ],
        
        'monitoring' => [
            'log_level' => 'debug',
            'alert_threshold' => 3,
            'metrics_interval' => 60,
            'retention_period' => 2592000, // 30 days
        ],
        
        'rate_limiting' => [
            'enabled' => true,
            'max_requests' => 60,
            'decay_minutes' => 1,
            'headers' => true,
        ]
    ],

    'cache' => [
        'default' => 'redis',
        'prefix' => 'cms_',
        'ttl' => 3600,
        
        'stores' => [
            'redis' => [
                'driver' => 'redis',
                'connection' => 'cache',
                'lock_connection' => 'default',
            ],
        ],
        
        'locks' => [
            'driver' => 'redis',
            'prefix' => 'cms_lock:',
            'ttl' => 300,
        ]
    ],

    'database' => [
        'default' => 'mysql',
        
        'connections' => [
            'mysql' => [
                'driver' => 'mysql',
                'read' => [
                    'host' => env('DB_HOST_READ', '127.0.0.1'),
                ],
                'write' => [
                    'host' => env('DB_HOST_WRITE', '127.0.0.1'),
                ],
                'sticky' => true,
                'database' => env('DB_DATABASE', 'cms'),
                'username' => env('DB_USERNAME', 'cms'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => 'InnoDB',
                'options' => extension_loaded('pdo_mysql') ? array_filter([
                    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                ]) : [],
            ],
        ],
        
        'redis' => [
            'client' => env('REDIS_CLIENT', 'phpredis'),
            'default' => [
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'password' => env('REDIS_PASSWORD', null),
                'port' => env('REDIS_PORT', 6379),
                'database' => env('REDIS_DB', 0),
                'read_write_timeout' => 60,
            ],
        ]
    ],

    'monitoring' => [
        'enabled' => true,
        'detailed_logging' => true,
        
        'metrics' => [
            'enabled' => true,
            'storage' => 'redis',
            'interval' => 60,
        ],
        
        'alerting' => [
            'enabled' => true,
            'channels' => ['slack', 'email'],
            'throttle' => 300,
        ],
        
        'thresholds' => [
            'cpu_usage' => 80,
            'memory_usage' => 75,
            'disk_usage' => 85,
            'response_time' => 500,
        ]
    ]
];
