<?php

namespace App\Core;

return [
    'name' => 'critical-cms',
    'version' => '1.0.0',

    'autoload' => [
        'psr-4' => [
            'App\\Core\\' => 'src/',
            'App\\Core\\Tests\\' => 'tests/'
        ]
    ],

    'require' => [
        'php' => '^8.1',
        'laravel/framework' => '^10.0',
        'predis/predis' => '^2.0'
    ],

    'require-dev' => [
        'phpunit/phpunit' => '^10.0'
    ],

    'extra' => [
        'laravel' => [
            'providers' => [
                'App\\Core\\ServiceProvider'
            ]
        ]
    ],

    'minimum-stability' => 'stable',
    
    'files' => [
        // Core Systems
        'src/CoreSystem.php',
        'src/Security/SecurityManager.php',
        'src/Auth/AuthenticationManager.php',
        'src/CMS/ContentManager.php',
        'src/Template/TemplateManager.php',
        'src/Infrastructure/InfrastructureManager.php',

        // Database
        'database/migrations/2024_01_01_000000_create_cms_tables_initial.php',

        // Deployment
        'src/Deploy/DeploymentManager.php',

        // Tests
        'tests/Core/CriticalSystemTest.php'
    ],

    'config' => [
        'cms.php',
        'security.php',
        'cache.php'
    ]
];
