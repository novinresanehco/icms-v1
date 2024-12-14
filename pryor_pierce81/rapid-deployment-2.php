<?php

return [
    'core' => [
        'security' => [
            'auth' => true,
            'encryption' => true,
            'monitoring' => true
        ],
        'content' => [
            'validation' => true,
            'caching' => true,
            'versioning' => false // Not critical for initial release
        ],
        'storage' => [
            'type' => 'db',
            'cache' => true,
            'backup' => true
        ]
    ],
    'monitoring' => [
        'performance' => true,
        'security' => true,
        'errors' => true
    ],
    'features' => [
        'core' => [
            'content' => true,
            'users' => true,
            'media' => true
        ],
        'advanced' => [
            'workflow' => false,
            'preview' => false,
            'analytics' => false
        ]
    ]
];
