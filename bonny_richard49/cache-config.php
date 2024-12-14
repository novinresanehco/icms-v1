<?php

return [
    'default' => env('CACHE_DRIVER', 'redis'),

    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                'compression' => true,
                'serializer' => 'json',
                'prefix_key' => 'v1',
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'cms_cache'),

    'ttl' => [
        'default' => 3600,
        'high' => 86400,
        'low' => 300,
        'permanent' => null,
    ],

    'security' => [
        'encryption' => [
            'enabled' => true,
            'algorithm' => 'AES-256-CBC',
        ],
        'key_rotation' => [
            'enabled' => true,
            'interval' => 86400,
        ],
        'access_control' => [
            'enabled' => true,
            'strict' => true,
        ],
    ],

    'monitoring' => [
        'enabled' => true,
        'metrics' => [
            'hits' => true,
            'misses' => true,
            'keys' => true,
            'memory' => true,
        ],
        'alerts' => [
            'memory_threshold' => 80,
            'miss_rate_threshold' => 20,
        ],
    ],

    'optimization' => [
        'compression' => [
            'enabled' => true,
            'threshold' => 1024,
        ],
        'serialization' => [
            'driver' => 'igbinary',
            'fallback' => 'php',
        ],
        'tags' => [
            'enabled' => true,
            'driver' => 'redis',
        ],
    ],

    'maintenance' => [
        'cleanup' => [
            'enabled' => true,
            'schedule' => 'daily',
            'threshold' => 10000,
        ],
        'backup' => [
            'enabled' => true,
            'schedule' => 'daily',
            'retention' => 7,
        ],
    ],

    'fallback' => [
        'enabled' => true,
        'store' => 'file',
        'threshold' => 3,
        'retry_after' => 5,
    ],

    'locks' => [
        'driver' => 'redis',
        'prefix' => 'lock:',
        'default_timeout' => 10,
        'retry' => [
            'times' => 3,
            'sleep' => 100,
        ],
    ],
];
