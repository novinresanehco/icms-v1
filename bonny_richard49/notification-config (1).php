<?php
// config/notifications.php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Notification Channels
    |--------------------------------------------------------------------------
    |
    | This option defines the default channels that will be used for sending
    | notifications. The channels can be overridden at the notification level.
    |
    */
    'default_channels' => [
        'database',
        'mail',
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Configurations
    |--------------------------------------------------------------------------
    |
    | Here you can configure settings for each notification channel.
    |
    */
    'channels' => [
        'mail' => [
            'throttle' => [
                'limit' => 100, // Maximum emails per minute
                'time_window' => 60, // Time window in seconds
            ],
            'retry' => [
                'max_attempts' => 3,
                'initial_delay' => 30, // Delay in seconds
                'multiplier' => 2, // Exponential backoff multiplier
            ],
            'templates' => [
                'path' => resource_path('views/notifications/email'),
                'default' => 'default',
            ],
        ],

        'slack' => [
            'webhook_url' => env('SLACK_WEBHOOK_URL'),
            'retry' => [
                'max_attempts' => 2,
                'delay' => 30,
            ],
            'templates' => [
                'path' => resource_path('views/notifications/slack'),
                'default' => 'default',
            ],
        ],

        'database' => [
            'cleanup' => [
                'enabled' => true,
                'days_to_keep' => 30,
                'schedule' => '0 0 * * *', // Daily at midnight
            ],
            'prune_read' => [
                'enabled' => true,
                'days_to_keep' => 7,
            ],
        ],

        'sms' => [
            'provider' => env('SMS_PROVIDER', 'twilio'),
            'from' => env('SMS_FROM_NUMBER'),
            'throttle' => [
                'limit' => 50, // Maximum SMS per minute
                'time_window' => 60,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how notifications should be queued.
    |
    */
    'queue' => [
        'enable' => true,
        'connection' => env('NOTIFICATION_QUEUE_CONNECTION', 'redis'),
        'queue' => env('NOTIFICATION_QUEUE', 'notifications'),
        'retry_after' => 90,
        'timeout' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching settings for notifications.
    |
    */
    'cache' => [
        'enable' => true,
        'prefix' => 'notifications:',
        'ttl' => 3600, // Time in seconds
        'tags_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Alerts
    |--------------------------------------------------------------------------
    |
    | Configure notification monitoring and alert thresholds.
    |
    */
    'monitoring' => [
        'enable' => true,
        'alert_threshold' => 5, // Number of failures before alerting
        'metrics' => [
            'enable' => true,
            'tags' => ['application', 'notifications'],
        ],
        'log_channel' => env('NOTIFICATION_LOG_CHANNEL', 'notifications'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Configuration
    |--------------------------------------------------------------------------
    |
    | Configure fallback options for failed notifications.
    |
    */
    'fallback' => [
        'enable' => true,
        'channels' => ['mail', 'database'], // Fallback channels in order
        'max_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security & Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure security settings and rate limiting.
    |
    */
    'security' => [
        'rate_limiting' => [
            'enable' => true,
            'max_per_minute' => 60,
            'decay_minutes' => 1,
        ],
        'encryption' => [
            'enable' => true,
            'key' => env('NOTIFICATION_ENCRYPTION_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Configuration
    |--------------------------------------------------------------------------
    |
    | Configure template settings and defaults.
    |
    */
    'templates' => [
        'cache' => [
            'enable' => true,
            'ttl' => 3600,
        ],
        'compile' => [
            'cache' => true,
            'minify' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    |
    | Configure batch processing settings.
    |
    */
    'batch' => [
        'enable' => true,
        'size' => 100, // Maximum notifications per batch
        'delay' => 10, // Delay between batches in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    |
    | Configure performance optimization settings.
    |
    */
    'performance' => [
        'chunk_size' => 100, // Size for chunked operations
        'prefetch' => true, // Enable prefetching of templates
        'optimize_images' => true, // Optimize images in notifications
    ],
];