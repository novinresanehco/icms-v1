// config/cms.php
<?php

return [
    'core' => [
        'content' => [
            'validation' => [
                'enabled' => true,
                'strict_mode' => true,
                'required_fields' => [
                    'title',
                    'content',
                    'status',
                    'author_id'
                ],
                'max_length' => [
                    'title' => 255,
                    'content' => 65535,
                    'excerpt' => 500
                ],
                'status_values' => [
                    'draft',
                    'pending',
                    'published',
                    'archived'
                ]
            ],

            'versioning' => [
                'enabled' => true,
                'max_versions' => 10,
                'diff_storage' => true,
                'author_tracking' => true,
                'restoration' => true
            ],

            'cache' => [
                'enabled' => true,
                'driver' => 'redis',
                'ttl' => 3600,
                'prefix' => 'cms_content:',
                'tags' => true
            ]
        ],

        'security' => [
            'input_validation' => [
                'sanitize_html' => true,
                'allow_iframes' => false,
                'allow_scripts' => false,
                'allow_uploads' => true
            ],

            'access_control' => [
                'enabled' => true,
                'rbac' => true,
                'default_role' => 'editor',
                'super_admin' => 'admin',
                'guest_access' => false
            ],

            'media' => [
                'allowed_types' => [
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'application/pdf'
                ],
                'max_size' => 5242880, // 5MB
                'scan_virus' => true,
                'optimize_images' => true
            ]
        ],

        'performance' => [
            'caching' => [
                'content' => true,
                'queries' => true,
                'routes' => true,
                'config' => true
            ],
            
            'optimization' => [
                'minify_html' => true,
                'compress_output' => true,
                'lazy_loading' => true,
                'db_indexing' => true
            ],

            'limits' => [
                'max_items_per_page' => 100,
                'max_search_results' => 1000,
                'query_timeout' => 5, // seconds
                'request_timeout' => 30 // seconds
            ]
        ]
    ],

    'monitoring' => [
        'content_tracking' => [
            'enabled' => true,
            'revisions' => true,
            'author_actions' => true,
            'user_activity' => true
        ],

        'performance_metrics' => [
            'enabled' => true,
            'response_time' => true,
            'memory_usage' => true,
            'cache_hits' => true,
            'query_analysis' => true
        ],

        'error_tracking' => [
            'enabled' => true,
            'log_level' => 'error',
            'notify_admin' => true,
            'stack_trace' => true
        ]
    ],

    'backup' => [
        'content' => [
            'enabled' => true,
            'frequency' => 'daily',
            'retention' => 30, // days
            'include_media' => true
        ],

        'database' => [
            'enabled' => true,
            'frequency' => 'hourly',
            'retention' => 168, // 7 days
            'compress' => true
        ],

        'configuration' => [
            'enabled' => true,
            'frequency' => 'daily',
            'retention' => 90, // days
            'encrypt' => true
        ]
    ],

    'api' => [
        'enabled' => true,
        'versioning' => true,
        'rate_limiting' => [
            'enabled' => true,
            'max_requests' => 60,
            'decay_minutes' => 1
        ],
        'authentication' => [
            'token_based' => true,
            'jwt' => true,
            'token_expiry' => 3600 // 1 hour
        ],
        'documentation' => [
            'enabled' => true,
            'auto_generate' => true,
            'format' => 'openapi'
        ]
    ]
];
