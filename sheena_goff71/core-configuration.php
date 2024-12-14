<?php

return [
    /**
     * Core Security Configuration
     * CRITICAL: These settings directly impact system security
     */
    'security' => [
        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation' => 24, // hours
            'min_key_length' => 32,
        ],
        'authentication' => [
            'multi_factor' => true,
            'session_lifetime' => 15, // minutes
            'max_attempts' => 3,
            'lockout_duration' => 30, // minutes
        ],
        'access_control' => [
            'strict_mode' => true,
            'permission_check' => 'all_levels',
            'audit_all_access' => true,
        ],
        'data_protection' => [
            'sanitize_all_input' => true,
            'encrypt_sensitive_data' => true,
            'validate_all_output' => true,
        ]
    ],

    /**
     * CMS Core Configuration
     * Integrated with security layer
     */
    'cms' => [
        'content' => [
            'validation' => [
                'max_title_length' => 200,
                'max_content_size' => 1024 * 1024, // 1MB
                'allowed_tags' => ['p', 'h1', 'h2', 'h3', 'strong', 'em', 'ul', 'ol', 'li'],
            ],
            'versioning' => [
                'enabled' => true,
                'max_versions' => 10,
                'auto_cleanup' => true,
            ],
            'cache' => [
                'enabled' => true,
                'ttl' => 3600,
                'invalidation_strategy' => 'smart',
            ]
        ],
        'media' => [
            'storage' => [
                'disk' => 'secure',
                'path_prefix' => 'media',
                'url_signing' => true,
            ],
            'processing' => [
                'max_file_size' => 10 * 1024 * 1024, // 10MB
                'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'],
                'virus_scan' => true,
            ]
        ],
    ],

    /**
     * Infrastructure Configuration
     * Critical for system stability
     */
    'infrastructure' => [
        'performance' => [
            'max_execution_time' => 30, // seconds
            'memory_limit' => '256M',
            'cpu_threshold' => 70, // percent
            'io_threshold' => 80, // percent
        ],
        'caching' => [
            'driver' => 'redis',
            'prefix' => 'cms_',
            'default_ttl' => 3600,
            'emergency_purge' => 90, // percent full
        ],
        'monitoring' => [
            'metrics_interval' => 60, // seconds
            'alert_thresholds' => [
                'response_time' => 500, // ms
                'error_rate' => 1, // percent
                'memory_usage' => 85, // percent
            ],
        ],
        'database' => [
            'max_connections' => 100,
            'query_timeout' => 5, // seconds
            'slow_query_threshold' => 1, // seconds
            'deadlock_timeout' => 3, // seconds
        ],
    ],

    /**
     * Validation Configuration
     * Zero-tolerance error checking
     */
    'validation' => [
        'strict_mode' => true,
        'sanitization' => [
            'escape_html' => true,
            'strip_tags' => true,
            'normalize_whitespace' => true,
        ],
        'type_checking' => [
            'strict_types' => true,
            'coerce_values' => false,
            'null_handling' => 'strict',
        ],
        'error_handling' => [
            'throw_on_first' => true,
            'detailed_messages' => true,
            'log_all_failures' => true,
        ]
    ],

    /**
     * Audit Configuration
     * Complete system monitoring
     */
    'audit' => [
        'events' => [
            'log_all_access' => true,
            'log_all_changes' => true,
            'log_security_events' => true,
            'log_performance_metrics' => true,
        ],
        'storage' => [
            'driver' => 'database',
            'retention_days' => 90,
            'encryption' => true,
        ],
        'alerts' => [
            'enabled' => true,
            'channels' => ['email', 'slack', 'database'],
            'threshold' => 'warning',
        ]
    ],
];
