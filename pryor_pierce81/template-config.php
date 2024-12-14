<?php

return [
    'template' => [
        'cache' => [
            'enabled' => true,
            'ttl' => 3600,
            'prefix' => 'template_'
        ],
        'security' => [
            'strict_validation' => true,
            'sandbox_enabled' => true,
            'allowed_functions' => [
                'e',
                'trans',
                'route'
            ]
        ],
        'performance' => [
            'minify_html' => true,
            'compress_output' => true,
            'cache_compiled' => true
        ]
    ],
    'media' => [
        'optimization' => [
            'enabled' => true,
            'quality' => 85,
            'max_width' => 1920,
            'max_height' => 1080
        ],
        'security' => [
            'allowed_types' => [
                'image/jpeg',
                'image/png',
                'image/webp'
            ],
            'max_size' => 5242880 // 5MB
        ]
    ],
    'components' => [
        'validation' => true,
        'cache_lifetime' => 3600,
        'strict_props' => true
    ]
];
