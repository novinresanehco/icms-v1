// config/security.php
<?php

return [
    'core' => [
        'validation' => [
            'enabled' => true,
            'strict_mode' => true,
            'input_validation' => true,
            'output_sanitization' => true,
            'content_validation' => true,
            'timeout' => 30, // seconds
            'max_attempts' => 3,
            'lockout_time' => 300, // seconds
        ],

        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation' => 86400, // 24 hours
            'key_storage' => 'secure',
            'hash_algo' => 'sha512',
            'cipher_options' => [
                'tag_length' => 16,
                'use_aad' => true,
                'key_derivation' => 'pbkdf2'
            ],
        ],

        'session' => [
            'timeout' => 900, // 15 minutes
            'regenerate' => true,
            'secure' => true,
            'http_only' => true,
            'same_site' => 'strict',
            'validation' => true,
            'fingerprint' => true,
        ],

        'headers' => [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ]
    ],

    'monitoring' => [
        'real_time' => [
            'enabled' => true,
            'interval' => 10, // seconds
            'metrics' => [
                'cpu_usage',
                'memory_usage',
                'disk_usage',
                'network_io',
                'error_rate',
                'response_time'
            ]
        ],

        'thresholds' => [
            'cpu_usage' => 80,         // percentage
            'memory_usage' => 85,      // percentage
            'disk_usage' => 90,        // percentage
            'response_time' => 200,    // milliseconds
            'error_rate' => 1,         // percentage
            'concurrent_users' => 1000
        ],

        'alerts' => [
            'channels' => ['email', 'slack', 'sms'],
            'levels' => ['emergency', 'alert', 'critical', 'error'],
            'throttle' => 300 // 5 minutes
        ]
    ],

    'protection' => [
        'rate_limiting' => [
            'enabled' => true,
            'max_attempts' => 60,
            'decay_minutes' => 1,
            'prefix' => 'rate_limit:',
            'middleware' => true
        ],

        'firewall' => [
            'enabled' => true,
            'whitelist' => [],
            'blacklist' => [],
            'auto_block' => true,
            'block_time' => 3600, // 1 hour
            'notification' => true
        ],

        'content_security' => [
            'sanitization' => [
                'html' => true,
                'javascript' => true,
                'css' => true,
                'sql' => true,
                'files' => true
            ],
            'allowed_tags' => [
                'p', 'br', 'b', 'i', 'em', 'strong', 'a', 'ul', 'ol', 'li',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img'
            ],
            'allowed_attributes' => [
                'href', 'src', 'alt', 'title', 'class', 'id'
            ],
            'file_types' => [
                'image/jpeg', 'image/png', 'image/gif', 'application/pdf'
            ],
            'max_file_size' => 5242880 // 5MB
        ]
    ],

    'audit' => [
        'enabled' => true,
        'detailed' => true,
        'storage' => [
            'driver' => 'database',
            'connection' => 'audit',
            'retention' => 90 // days
        ],
        'events' => [
            'auth' => true,
            'crud' => true,
            'system' => true,
            'security' => true
        ],
        'masking' => [
            'enabled' => true,
            'fields' => [
                'password',
                'credit_card',
                'token',
                'secret'
            ]
        ]
    ],

    'recovery' => [
        'backup' => [
            'enabled' => true,
            'frequency' => 'hourly',
            'retention' => 168, // 7 days
            'compression' => true,
            'encryption' => true
        ],

        'failover' => [
            'enabled' => true,
            'automatic' => true,
            'threshold' => 3,
            'timeout' => 5
        ],

        'rollback' => [
            'enabled' => true,
            'automatic' => true,
            'versions' => 5,
            'validation' => true
        ]
    ],

    'compliance' => [
        'standards' => [
            'pci_dss' => true,
            'hipaa' => true,
            'gdpr' => true
        ],
        'validation' => [
            'automated' => true,
            'frequency' => 'daily',
            'reporting' => true
        ],
        'data_retention' => [
            'enabled' => true,
            'schedule' => 'daily',
            'default_period' => 365 // days
        ]
    ]
];
