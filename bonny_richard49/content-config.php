<?php

return [
    'storage' => [
        'driver' => 'database',
        'table' => 'contents',
        'media_disk' => 'public',
        'backup_disk' => 'secure'
    ],

    'security' => [
        'validation' => [
            'enabled' => true,
            'sanitize_input' => true,
            'max_size' => 10485760, // 10MB
            'allowed_tags' => '<p><br><strong><em><ul><ol><li><a><img>',
            'allowed_attributes' => [
                'a' => ['href', 'title', 'target'],
                'img' => ['src', 'alt', 'title', 'width', 'height']
            ]
        ],
        'permissions' => [
            'strict' => true,
            'require_auth' => true,
            'auto_publish' => false,
            'review_required' => true
        ],
        'encryption' => [
            'enabled' => true,
            'fields' => ['metadata', 'secure_data']
        ]
    ],

    'versioning' => [
        'enabled' => true,
        'max_versions' => 10,
        'auto_cleanup' => true,
        'retention' => [
            'days' => 30,
            'minimum_keep' => 3
        ]
    ],

    'cache' => [
        'enabled' => true,
        'driver' => 'redis',
        'prefix' => 'content:',
        'ttl' => 3600,
        'tags' => [
            'enabled' => true,
            'flush_on_update' => true
        ]
    ],

    'search' => [
        'engine' => 'database',
        'index_fields' => ['title', 'content', 'metadata'],
        'weights' => [
            'title' => 3,
            'content' => 1,
            'metadata' => 2
        ],
        'min_score' => 0.3
    ],

    'media' => [
        'processing' => [
            'enabled' => true,
            'image_optimization' => true,
            'max_dimensions' => [1920, 1080],
            'thumbnails' => [
                [150, 150],
                [300, 300],
                [600, 600]
            ]
        ],
        'validation' => [
            'max_size' => 5242880, // 5MB
            'allowed_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'application/pdf'
            ]
        ]
    ],

    'performance' => [
        'chunk_size' => 100,
        'max_results' => 1000,
        'cache_warming' => true,
        'lazy_loading' => true,
        'monitoring' => [
            'enabled' => true,
            'metrics' => [
                'response_time',
                'memory_usage',
                'cache_hits'
            ]
        ]
    ],

    'audit' => [
        'enabled' => true,
        'events' => [
            'create',
            'update',
            'delete',
            'publish',
            'unpublish'
        ],
        'retention' => 90 // days
    ]
];
