<?php

return [
    'enabled' => true,

    'metrics' => [
        'storage' => 'redis',
        'retention' => [
            'raw' => 24 * 3600, // 1 day
            'aggregated' => 30 * 24 * 3600 // 30 days
        ],
        'aggregation' => [
            'interval' => 60, // seconds
            'functions' => ['avg', 'min', 'max', 'count']
        ]
    ],

    'thresholds' => [
        'response_time' => 200, // milliseconds
        'database_query' => 100, // milliseconds
        'memory_limit' => 128 * 1024 * 1024, // 128MB
        'cpu_limit' => 80, // percentage
        'error_rate' => 1, // errors per minute
        'failed_jobs' => 5, // per hour
        'disk_usage' => 85, // percentage
        'connection_limit' => 1000, // concurrent connections
        'queue_size' => 1000, // maximum queue size
        'cache_hit_rate' => 80, // percentage
    ],

    'alerts' => [
        'channels' => ['slack', 'email'],
        'throttle' => [
            'enabled' => true,
            'frequency' => 300, // 5 minutes
            'max_alerts' => 10 // per window
        ],
        'priorities' => [
            'critical' => ['slack', 'email', 'sms'],
            'high' => ['slack', 'email'],
            'medium' => ['slack'],
            'low' => ['log']
        ],
        'escalation' => [
            'enabled' => true,
            'delay' => 1800, // 30 minutes
            'levels' => ['team', 'manager', 'director']
        ]
    ],

    'logging' => [
        'level' => 'warning',
        'channels' => ['daily', 'slack'],
        'retention' => 30, // days
        'max_files' => 30,
        'format' => 'json',
        'context' => [
            'include_user' => true,
            'include_request' => true,
            'include_session' => true
        ]
    ],

    'performance' => [
        'tracking' => [
            'enabled' => true,
            'sample_rate' => 0.1, // 10% of requests
            'slow_threshold' => 1000, // milliseconds
            'profiling' => [
                'enabled' => true,
                'threshold' => 500, // milliseconds
                'max_depth' => 10
            ],
            'tracing' => [
                'enabled' => true,
                'sample_rate' => 0.01 // 1% of requests
            ]
        ],
        'optimization' => [
            'auto_scale' => true,
            'cache_warming' => true,
            'query_caching' => true
        ]
    ],

    'security' => [
        'audit_log' => true,
        'suspicious_activity' => [
            'login_attempts' => 5,
            'api_rate' => 100, // per minute
            'error_threshold' => 10, // per minute
            'ip_tracking' => true,
            'user_tracking' => true
        ],
        'monitoring' => [
            'file_integrity' => true,
            'dependency_check' => true,
            'vulnerability_scan' => true,
            'interval' => 3600 // hourly
        ]
    ],

    'resources' => [
        'tracking' => [
            'memory' => true,
            'cpu' => true,
            'disk' => true,
            'network' => true,
            'database' => [
                'connections' => true,
                'deadlocks' => true,
                'slow_queries' => true
            ],
            'cache' => [
                'hits' => true,
                'misses' => true,
                'size' => true
            ],
            'queue' => [
                'size' => true,
                'processing_time' => true,
                'failure_rate' => true
            ]
        ],
        'limits' => [
            'action' => 'alert', // alert, throttle, or shutdown
            'notification' => 'immediate',
            'recovery' => [
                'strategy' => 'gradual',
                'steps' => ['cache_clear', 'queue_restart', 'service_restart']
            ]
        ]
    ],

    'health_checks' => [
        'enabled' => true,
        'interval' => 60, // seconds
        'timeout' => 5, // seconds
        'services' => [
            'database' => true,
            'cache' => true,
            'queue' => true,
            'storage' => true,
            'external_apis' => true
        ],
        'recovery' => [
            'attempts' => 3,
            'delay' => 5, // seconds
            'backoff' => 'exponential'
        ]
    ],

    'analytics' => [
        'enabled' => true,
        'retention' => 90, // days
        'aggregations' => [
            '1m' => 24 * 60, // 1 day of minute-level data
            '5m' => 7 * 24 * 12, // 1 week of 5-minute data
            '1h' => 30 * 24, // 30 days of hourly data
            '1d' => 365 // 1 year of daily data
        ],
        'exports' => [
            'enabled' => true,
            'format' => 'json',
            'schedule' => 'daily',
            'retention' => 30 // days
        ]
    ],

    'maintenance' => [
        'auto_cleanup' => [
            'enabled' => true,
            'schedule' => 'daily',
            'targets' => [
                'logs' => true,
                'temp_files' => true,
                'old_backups' => true,
                'cache' => true
            ]
        ],
        'backup' => [
            'metrics' => true,
            'logs' => true,
            'retention' => 30 // days
        ]
    ],

    'reporting' => [
        'enabled' => true,
        'schedule' => 'daily',
        'formats' => ['pdf', 'json'],
        'recipients' => [
            'technical' => ['devops@company.com'],
            'business' => ['management@company.com']
        ],
        'contents' => [
            'system_health' => true,
            'performance_metrics' => true,
            'security_events' => true,
            'resource_usage' => true,
            'error_summary' => true
        ]
    ]
];
