// config/monitoring.php
<?php

return [
    'core' => [
        'system' => [
            'metrics' => [
                'collection' => [
                    'enabled' => true,
                    'interval' => 10,  // seconds
                    'store' => 'redis',
                    'retention' => 604800  // 7 days
                ],
                'thresholds' => [
                    'cpu_usage' => 80,     // percent
                    'memory_usage' => 85,   // percent
                    'disk_usage' => 90,     // percent
                    'load_average' => 5,    // 5 minute load
                    'network_io' => 80     // percent of capacity
                ],
                'alerts' => [
                    'channels' => ['slack', 'email'],
                    'throttle' => 300,  // 5 minutes
                    'escalation' => true
                ]
            ],

            'logging' => [
                'enabled' => true,
                'level' => 'debug',
                'channels' => [
                    'daily' => true,
                    'slack' => true,
                    'database' => true
                ],
                'retention' => 30  // days
            ],

            'tracing' => [
                'enabled' => true,
                'sample_rate' => 0.1,
                'store' => 'database',
                'retention' => 7  // days
            ]
        ],

        'performance' => [
            'tracking' => [
                'enabled' => true,
                'response_time' => true,
                'memory_usage' => true,
                'query_time' => true,
                'cache_hits' => true
            ],

            'profiling' => [
                'enabled' => true,
                'slow_queries' => 100,    // milliseconds
                'slow_requests' => 1000,   // milliseconds
                'memory_leaks' => true
            ],

            'optimization' => [
                'auto_scale' => true,
                'cache_warming' => true,
                'query_caching' => true,
                'compression' => true
            ]
        ],

        'security' => [
            'audit' => [
                'enabled' => true,
                'events' => [
                    'authentication' => true,
                    'authorization' => true,
                    'data_access' => true,
                    'system_changes' => true
                ],
                'retention' => 90  // days
            ],

            'intrusion' => [
                'detection' => true,
                'prevention' => true,
                'blacklist' => true,
                'rate_limiting' => true
            ],

            'vulnerability' => [
                'scanning' => true,
                'frequency' => 'daily',
                'auto_patch' => true,
                'reporting' => true
            ]
        ]
    ],

    'infrastructure' => [
        'health_checks' => [
            'enabled' => true,
            'interval' => 60,  // seconds
            'services' => [
                'database' => true,
                'cache' => true,
                'queue' => true,
                'storage' => true
            ],
            'notifications' => true
        ],

        'backup_monitoring' => [
            'enabled' => true,
            'verify_integrity' => true,
            'alert_on_failure' => true,
            'retention_check' => true
        ],

        'capacity_planning' => [
            'enabled' => true,
            'predict_growth' => true,
            'alert_threshold' => 80,  // percent
            'auto_scale' => true
        ]
    ],

    'application' => [
        'error_tracking' => [
            'enabled' => true,
            'detailed' => true,
            'stack_trace' => true,
            'context' => true,
            'notify' => true
        ],

        'user_monitoring' => [
            'session_tracking' => true,
            'activity_log' => true,
            'performance_impact' => true