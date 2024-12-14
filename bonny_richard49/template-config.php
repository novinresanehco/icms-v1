<?php

return [
    'paths' => [
        'templates' => storage_path('app/templates'),
        'compiled' => storage_path('app/templates/compiled'),
        'cache' => storage_path('app/templates/cache'),
    ],

    'security' => [
        'sandbox' => [
            'enabled' => true,
            'blacklist_functions' => [
                'exec', 'system', 'passthru', 'shell_exec', 
                'proc_open', 'popen', 'curl_exec', 
                'parse_ini_file', 'show_source'
            ],
            'blacklist_classes' => [
                'ReflectionClass', 'PDO', 'DirectoryIterator',
                'FilesystemIterator', 'GlobIterator'
            ],
            'whitelist_functions' => [
                'date', 'strtotime', 'number_format', 
                'round', 'floor', 'ceil', 'count'
            ],
        ],
        'validation' => [
            'enable_php' => false,
            'enable_includes' => false,
            'max_includes' => 10,
            'max_iterations' => 100,
            'max_execution_time' => 5
        ],
        'input_validation' => [
            'escape_html' => true,
            'allowed_tags' => '<p><br><strong><em><ul><li><ol><a><img>',
            'max_length' => 10000
        ]
    ],

    'cache' => [
        'enabled' => true,
        'driver' => 'redis',
        'prefix' => 'template:',
        'ttl' => 3600,
    ],

    'compilation' => [
        'strict_variables' => true,
        'strict_functions' => true,
        'debug' => false,
        'auto_reload' => true,
        'optimize' => true
    ],

    'performance' => [
        'cache_warming' => true,
        'precompile' => true,
        'minify' => true,
        'compress' => true,
        'monitoring' => [
            'enabled' => true,
            'slow_threshold' => 100, // ms
            'memory_threshold' => 32 // MB
        ]
    ],

    'extensions' => [
        'enabled' => true,
        'auto_load' => true,
        'path' => app_path('Extensions/Template'),
        'namespace' => 'App\\Extensions\\Template'
    ],
];
